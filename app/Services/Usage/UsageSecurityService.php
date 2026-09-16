<?php

namespace App\Services\Usage;

use Illuminate\Support\Facades\DB;

class UsageSecurityService
{
    public function snapshot(array $scope, int $from, int $to, bool $admin, int $actor): array
    {
        $query = app(UsageQueryService::class);
        $first = $query->events($scope, ['kind' => 'connection', 'per_page' => 200], $from, $to, $admin);
        $panel = $query->events($scope, ['kind' => 'panel', 'result' => '失败', 'per_page' => 200], $from, $to, $admin);
        $subscription = $query->events($scope, ['kind' => 'subscription', 'result' => '失败', 'per_page' => 200], $from, $to, $admin);
        $events = array_merge(array_map(fn($e) => array_replace($e, ['risk' => '新来源 IP']), $first['data']), $panel['data'], $subscription['data']);
        usort($events, fn($a, $b) => $b['at'] <=> $a['at']);
        $sources = DB::table('v2_usage_source as s')->leftJoin('v2_server as n', 'n.id', '=', 's.first_node_id')->whereBetween('s.first_seen', [$from, $to]);
        if (!empty($scope['user_id'])) $sources->where('s.user_id', $scope['user_id']);
        if (!empty($scope['node_id'])) $sources->where('s.first_node_id', $scope['node_id']);
        if (!empty($scope['machine_id'])) $sources->where('n.machine_id', $scope['machine_id']);
        $ips = (clone $sources)->distinct()->count('s.ip');
        $reviewed = $actor ? DB::table('v2_usage_review')->where('actor_id', $actor)->whereIn('signal', array_column($events, 'id'))->pluck('signal')->all() : [];
        return ['events' => $events, 'reviewed' => $reviewed, 'security' => [
            'newIps' => $ips, 'connectionIps' => $ips, 'connections' => $first['total'],
            'failed' => $panel['total'] + $subscription['total'],
            'totalSignals' => $first['total'] + $panel['total'] + $subscription['total'],
            'shownSignals' => count($events),
        ]];
    }

    public function review(int $actor, bool $admin, string $signal): void
    {
        abort_unless(\App\Services\Logs\LogSettings::enabled('review') && \App\Services\Logs\LogBudget::accepts(), 409, '安全核查记录已关闭或日志存储预算已满。');
        [$kind, $id] = array_pad(explode(':', $signal, 2), 2, '');
        abort_unless(in_array($kind, ['connection', 'panel', 'subscription'], true) && ctype_digit($id), 422, 'Invalid signal');
        $query = DB::table($kind === 'connection' ? 'v2_usage_source' : 'v2_usage_event')->where('id', $id);
        if (!$admin) $query->where('user_id', $actor);
        if ($kind !== 'connection') $query->where('kind', $kind)->where('result', '失败');
        abort_unless($query->exists(), 404, 'Signal not found');
        DB::table('v2_usage_review')->upsert([['actor_id' => $actor, 'signal' => $signal, 'reviewed_at' => time()]], ['actor_id', 'signal'], ['reviewed_at']);
    }
}
