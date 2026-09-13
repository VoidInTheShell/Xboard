<?php

namespace App\Services\Usage;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Independent observation ledger: never increments a user's billing counters.
 * A row-locked epoch/sequence fence and counter updates commit atomically.
 */
class UsageIngestService
{
    public function ingest(string $scope, int $sourceId, array $report, ?Server $node = null): bool
    {
        return DB::transaction(function () use ($scope, $sourceId, $report, $node) {
            $headKey = ['scope' => $scope, 'source_id' => $sourceId];
            DB::table('v2_usage_head')->insertOrIgnore($headKey);
            $head = DB::table('v2_usage_head')->where($headKey)->lockForUpdate()->first();
            $streamKey = ['scope' => $scope, 'source_id' => $sourceId, 'epoch' => $report['epoch']];
            if ($head->epoch !== '' && $head->epoch !== $report['epoch']) {
                // A known previous process must never become current again.
                if (DB::table('v2_usage_stream')->where($streamKey)->exists()
                    || $report['sampled_at'] <= $head->sampled_at) return false;
            }
            DB::table('v2_usage_stream')->insertOrIgnore($streamKey + [
                'sequence' => 0, 'sampled_at' => $report['sampled_at'],
            ]);
            $stream = DB::table('v2_usage_stream')->where($streamKey)->lockForUpdate()->first();
            if ((int) $stream->sequence >= $report['sequence']) return false;
            // An epoch's clock may not go backwards. Old reports cannot revive devices.
            abort_if($report['sampled_at'] < $stream->sampled_at, 422, 'Out-of-order sample');
            $at = (int) $report['sampled_at'];
            $machineId = $scope === 'machine' ? $sourceId : (int) ($node?->machine_id ?? 0);
            $nodeId = $node?->id ?? 0;
            $rows = $report['counters'];
            if ($scope === 'node') {
                $userIds = array_column($rows, 'user_id');
                // A reporting node cannot invent users; use the same group membership
                // as its configuration/user endpoint.
                $deviceUsers = array_column($report['devices'] ?? [], 'user_id');
                $allowed = \App\Models\User::query()
                    ->whereIn('id', array_unique(array_merge($userIds, $deviceUsers)))
                    ->whereIn('group_id', $node->group_ids ?? [])
                    ->pluck('id')->map(fn($id) => (int) $id)->all();
                abort_if(array_diff($userIds, $allowed) !== [], 403, 'User is not assigned to node');
                abort_if(array_diff($deviceUsers, $allowed) !== [], 403, 'Device user is not assigned to node');
            }
            $previous = DB::table('v2_usage_counter')->where('stream_id', $stream->id)
                ->get()->keyBy('resource');
            $instanceUp = 0;
            $instanceDown = 0;
            $counterWrites = [];
            $trafficWrites = [];
            foreach ($rows as $row) {
                $resource = $scope === 'node' ? 'user:' . $row['user_id'] : $row['interface'];
                $old = $previous->get($resource);
                // First sample establishes the baseline: lifetime interface counts
                // or a new agent epoch must not be billed into one current hour.
                $up = $old && $row['up'] >= $old->up ? $row['up'] - $old->up : 0;
                $down = $old && $row['down'] >= $old->down ? $row['down'] - $old->down : 0;
                $counterWrites[] = ['stream_id' => $stream->id, 'resource' => $resource, 'up' => $row['up'], 'down' => $row['down']];
                if ($up || $down) {
                    $rate = $scope === 'node' ? (float) $node->rate : 1.0;
                    $trafficWrites[] = [
                        'layer' => $scope === 'node' ? 'proxy' : 'nic',
                        'machine_id' => $machineId, 'node_id' => $nodeId,
                        'user_id' => $scope === 'node' ? $row['user_id'] : 0,
                        'resource' => $scope === 'node' ? '' : $resource,
                        'bucket' => intdiv($at, 3600) * 3600,
                        'up' => $up, 'down' => $down, 'billed_up' => (int) round($up * $rate), 'billed_down' => (int) round($down * $rate),
                    ];
                }
                $instanceUp += $up;
                $instanceDown += $down;
            }
            if ($scope === 'node') {
                if ($instanceUp || $instanceDown) $trafficWrites[] = [
                    'layer' => 'instance', 'machine_id' => $machineId,
                    'node_id' => $nodeId, 'user_id' => 0,
                    'resource' => $report['core'] . ':' . $nodeId,
                    'bucket' => intdiv($at, 3600) * 3600,
                    'up' => $instanceUp, 'down' => $instanceDown, 'billed_up' => $instanceUp, 'billed_down' => $instanceDown,
                ];
                $this->devices($node, $report['devices'] ?? [], $at);
                app(UsageIpIngestService::class)->ingest($node, $report['devices'] ?? [], $at);
            }
            foreach (array_chunk($counterWrites, 200) as $chunk) DB::table('v2_usage_counter')->upsert($chunk, ['stream_id', 'resource'], ['up', 'down']);
            $updates = [];
            foreach (['up', 'down', 'billed_up', 'billed_down'] as $column) {
                $incoming = DB::getDriverName() === 'mysql' ? "VALUES($column)" : "excluded.$column";
                $updates[$column] = DB::raw("$column + $incoming");
            }
            foreach (array_chunk($trafficWrites, 100) as $chunk) DB::table('v2_usage_traffic')->upsert(
                $chunk, ['layer', 'node_id', 'user_id', 'machine_id', 'resource', 'bucket'], $updates
            );
            DB::table('v2_usage_stream')->where('id', $stream->id)->update([
                'sequence' => $report['sequence'], 'sampled_at' => $at,
            ]);
            DB::table('v2_usage_head')->where('id', $head->id)->update(['epoch' => $report['epoch'], 'sampled_at' => $at,
                'devices_complete' => $report['devices_complete'] ?? true]);
            return true;
        }, 3);
    }

    private function devices(Server $node, array $devices, int $at): void
    {
        $identities = [];
        $normalized = [];
        foreach ($devices as $device) {
            $packed = inet_pton($device['ip']);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255)) $packed = substr($packed, 12);
            $ip = inet_ntop($packed);
            $uid = (int) $device['user_id'];
            $hash = hash_hmac('sha256', 'ip:' . $ip, config('app.key'));
            $key = $uid . ':' . $hash;
            $first = min($at, (int) ($device['first_seen'] ?? $at));
            $last = min($at, (int) ($device['last_seen'] ?? $at));
            abort_if($first > $last, 422, 'First observation must not follow last observation');
            $identities[$key] = ['user_id' => $uid, 'identity_hash' => $hash, 'source' => 'ip', 'first_seen' => $first, 'last_seen' => $last];
            $normalized[$key] = $device + ['canonical_ip' => $ip];
        }
        $incomingLastSeen = DB::connection()->getDriverName() === 'mysql' ? 'VALUES(last_seen)' : 'excluded.last_seen';
        $lastSeenUpdate = ['last_seen' => DB::raw("CASE WHEN last_seen < $incomingLastSeen THEN $incomingLastSeen ELSE last_seen END")];
        foreach (array_chunk(array_values($identities), 200) as $chunk) DB::table('v2_usage_identity')->upsert($chunk, ['user_id', 'identity_hash'], $lastSeenUpdate);
        $identityRows = DB::table('v2_usage_identity')
            ->whereIn('user_id', array_unique(array_column($identities, 'user_id')))
            ->whereIn('identity_hash', array_unique(array_column($identities, 'identity_hash')))
            ->get()->keyBy(fn($row) => $row->user_id . ':' . $row->identity_hash);
        $sources = [];
        foreach ($normalized as $key => $device) {
            $identity = $identityRows->get($key);
            $sources[] = ['identity_id' => $identity->id, 'user_id' => $identity->user_id, 'ip' => $device['canonical_ip'],
                'first_seen' => $identities[$key]['first_seen'], 'last_seen' => $identities[$key]['last_seen'], 'first_node_id' => $node->id];
        }
        $identityIds = array_column($sources, 'identity_id');
        foreach (array_chunk($sources, 200) as $chunk) DB::table('v2_usage_source')->upsert($chunk, ['identity_id', 'ip'], $lastSeenUpdate);
        $sourceRows = DB::table('v2_usage_source')->whereIn('identity_id', $identityIds)->get()->keyBy('identity_id');
        $online = [];
        foreach ($normalized as $key => $device) {
            if (!($device['online'] ?? true)) continue;
            $identity = $identityRows->get($key);
            $source = $sourceRows->get($identity->id);
            $online[] = ['node_id' => $node->id, 'source_id' => $source->id, 'machine_id' => $node->machine_id ?? 0,
                'user_id' => $identity->user_id, 'platform' => 'unknown',
                'up_speed' => $device['up_speed'] ?? null, 'down_speed' => $device['down_speed'] ?? null, 'sampled_at' => $at];
        }
        foreach (array_chunk($online, 200) as $chunk) DB::table('v2_usage_online')->upsert($chunk, ['node_id', 'source_id'], ['up_speed', 'down_speed', 'sampled_at', 'machine_id']);
        // Complete snapshot: empty explicitly means no devices; historical sources stay.
        DB::table('v2_usage_online')->where('node_id', $node->id)->whereNotIn('source_id', array_column($online, 'source_id'))->delete();
    }
}
