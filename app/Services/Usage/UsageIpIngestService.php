<?php

namespace App\Services\Usage;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/** Runs inside the node's locked, replay-fenced transaction. Never changes billing. */
class UsageIpIngestService
{
    public function ingest(Server $node, array $devices, int $at): void
    {
        $normalized = [];
        foreach ($devices as $device) {
            $packed = inet_pton($device['ip']);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255)) $packed = substr($packed, 12);
            $ip = inet_ntop($packed);
            $key = $device['user_id'] . ':' . $ip;
            abort_if(isset($normalized[$key]), 422, 'Duplicate canonical user/IP');
            $measured = isset($device['generation'], $device['up'], $device['down']);
            abort_if((isset($device['up']) || isset($device['down']) || isset($device['generation'])) && !$measured, 422, 'Incomplete IP counter');
            $normalized[$key] = $device + ['canonical_ip' => $ip, 'family' => strlen($packed) === 4 ? 4 : 6,
                'measured' => $measured, 'resource' => hash('sha256', $key . ':' . ($device['generation'] ?? 'unknown'))];
        }
        $previous = DB::table('v2_usage_ip_counter')->where('node_id', $node->id)
            ->whereIn('resource', array_column($normalized, 'resource'))->get()->keyBy('resource');
        $counters = [];
        $traffic = [];
        foreach ($normalized as $row) {
            $old = $previous->get($row['resource']);
            $measured = $row['measured'];
            if ($measured) {
                abort_if($old && ($row['up'] < $old->up || $row['down'] < $old->down), 422, 'IP counter reset requires a new generation');
                $up = $row['up'] - ($old->up ?? 0);
                $down = $row['down'] - ($old->down ?? 0);
                $counters[] = ['node_id' => $node->id, 'resource' => $row['resource'], 'up' => $row['up'], 'down' => $row['down'], 'sampled_at' => $at];
            } else {
                $up = $down = 0;
            }
            // An offline counter repeated after acknowledgement carries no new
            // traffic or connection. Do not manufacture daily rows for retries.
            if ($old && !($row['online'] ?? true) && !$up && !$down) continue;
            $first = min($at, (int) ($row['first_seen'] ?? $at));
            $last = min($at, (int) ($row['last_seen'] ?? $at));
            $gap = $measured && $at - ($old->sampled_at ?? $first) > 300;
            $bucket = intdiv($last, 3600) * 3600;
            $traffic[] = ['user_id' => $row['user_id'], 'ip' => $row['canonical_ip'], 'family' => $row['family'],
                'node_id' => $node->id, 'machine_id' => $node->machine_id ?? 0, 'bucket' => $bucket,
                'up' => $up, 'down' => $down, 'measured' => $measured ? 1 : 0, 'missing' => (!$measured || $gap) ? 1 : 0,
                'gaps' => $gap ? 1 : 0,
                'first_seen' => max($first, $bucket), 'last_seen' => $last];
        }
        foreach (array_chunk($counters, 150) as $chunk) DB::table('v2_usage_ip_counter')->upsert($chunk, ['node_id', 'resource'], ['up', 'down', 'sampled_at']);
        $updates = [];
        foreach (['up', 'down', 'measured', 'missing', 'gaps', 'first_seen', 'last_seen'] as $column) {
            $incoming = DB::getDriverName() === 'mysql' ? "VALUES($column)" : "excluded.$column";
            $updates[$column] = DB::raw(match ($column) {
                'first_seen' => "CASE WHEN first_seen > $incoming THEN $incoming ELSE first_seen END",
                'last_seen' => "CASE WHEN last_seen < $incoming THEN $incoming ELSE last_seen END",
                default => "$column + $incoming",
            });
        }
        foreach (array_chunk($traffic, 50) as $chunk) DB::table('v2_usage_ip_traffic')->upsert($chunk,
            ['user_id', 'ip', 'node_id', 'machine_id', 'bucket'], $updates);
    }
}
