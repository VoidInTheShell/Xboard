<?php

namespace App\Services\Usage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UsageIpQueryService
{
    private function groups(Builder $base, string $grouping): Builder
    {
        $q = (clone $base)->select('t.user_id', 't.ip')->groupBy('t.user_id', 't.ip');
        if ($grouping === 'connection') $q->addSelect('t.node_id')->groupBy('t.node_id');
        return $q->selectRaw('SUM(t.up) as upload, SUM(t.down) as download, SUM(t.up + t.down) as total,
            SUM(t.measured) as measured, SUM(t.missing) as missing, MIN(CASE WHEN t.first_seen < t.bucket THEN t.bucket ELSE t.first_seen END) as first_seen, MAX(t.last_seen) as last_seen');
    }

    public function query(array $scope, array $filters, int $from, int $to): array
    {
        $grouping = $filters['grouping'] ?? 'connection';
        $heads = DB::table('v2_usage_head as h')->join('v2_server as n', 'n.id', '=', 'h.source_id')->where('h.scope', 'node');
        if (!empty($scope['node_id'])) $heads->where('n.id', $scope['node_id']);
        if (!empty($scope['machine_id'])) $heads->where('n.machine_id', $scope['machine_id']);
        $complete = !(clone $heads)->where('h.devices_complete', false)->exists();
        $base = app(UsageQueryService::class)->scoped(DB::table('v2_usage_ip_traffic as t'), $scope, 't.')
            ->whereBetween('t.bucket', [intdiv($from, 3600) * 3600, $to]);
        if (!empty($filters['ip'])) $base->where('t.ip', inet_ntop(inet_pton($filters['ip'])));
        if (($filters['family'] ?? 'all') !== 'all') $base->where('t.family', $filters['family'] === 'v4' ? 4 : 6);
        if (trim($filters['search'] ?? '') !== '') {
            // Bound parameters; wildcard characters are literal, not a way to
            // expand the requested search or probe records outside the scope.
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['search'])) . '%';
            $base->where(function ($q) use ($term) {
                $q->whereRaw("t.ip LIKE ? ESCAPE '!'", [$term])
                    ->orWhereExists(fn($u) => $u->selectRaw('1')->from('v2_user as u')->whereColumn('u.id', 't.user_id')->whereRaw("u.email LIKE ? ESCAPE '!'", [$term]))
                    ->orWhereExists(fn($n) => $n->selectRaw('1')->from('v2_server as n')->whereColumn('n.id', 't.node_id')->whereRaw("n.name LIKE ? ESCAPE '!'", [$term]));
            });
        }
        $groups = $this->groups($base, $grouping);
        $coverage = $filters['coverage'] ?? 'all';
        if ($coverage !== 'all') $groups->havingRaw('SUM(t.missing) ' . ($coverage === 'partial' ? '> 0' : '= 0'));
        $eligible = (clone $base)->joinSub($groups, 'g', function ($join) use ($grouping) {
            $join->on('t.user_id', '=', 'g.user_id')->on('t.ip', '=', 'g.ip');
            if ($grouping === 'connection') $join->on('t.node_id', '=', 'g.node_id');
        });
        $totalRows = DB::query()->fromSub($groups, 'groups')->count();
        abort_if(($filters['detail'] ?? false) && $totalRows > 2000, 422, 'Narrow the IP detail date range');
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($totalRows / $perPage)));
        $sort = ['total' => 'total', 'upload' => 'upload', 'download' => 'download', 'first' => 'first_seen', 'last' => 'last_seen'][$filters['sort'] ?? 'total'];
        $rows = (clone $groups)->orderBy($sort, ($filters['desc'] ?? true) ? 'desc' : 'asc')->orderBy('t.user_id')->orderBy('t.ip');
        if ($grouping === 'connection') $rows->orderBy('t.node_id');
        $rows = $rows->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $sourceGroups = $this->groups($eligible, 'source');
        $sourceCount = DB::query()->fromSub($sourceGroups, 'sources')->count();
        $sources = (clone $sourceGroups)->orderByDesc('total')->orderBy('t.user_id')->orderBy('t.ip')->limit(6)->get();
        $summary = (clone $eligible)->selectRaw('COALESCE(SUM(t.up + t.down), 0) as total, COALESCE(SUM(t.measured), 0) as measured,
            COALESCE(SUM(t.missing), 0) as missing, COALESCE(SUM(t.gaps), 0) as gaps,
            COUNT(DISTINCT t.ip) as ips, COUNT(DISTINCT t.user_id) as users, COUNT(DISTINCT t.node_id) as nodes')->first();
        $grain = $to - $from <= 86400 ? 3600 : 86400;
        $bucket = "t.bucket - ((t.bucket + 28800) % $grain)";
        $trend = (clone $eligible)->selectRaw("$bucket as bucket, SUM(t.up) as up, SUM(t.down) as down, SUM(t.measured) as measured")
            ->groupByRaw($bucket)->orderBy('bucket')->get()->map(fn($r) => [
                'at' => max($from, (int) $r->bucket) * 1000, 'upload' => $r->measured ? $r->up / 1073741824 : null,
                'download' => $r->measured ? $r->down / 1073741824 : null,
                'userId' => '', 'user' => '', 'ip' => '', 'nodeId' => '', 'node' => '', 'serverId' => '',
            ])->all();
        $emails = DB::table('v2_user')->whereIn('id', $rows->pluck('user_id')->merge($sources->pluck('user_id'))->unique())->pluck('email', 'id');
        $nodeNames = DB::table('v2_server')->whereIn('id', (clone $eligible)->select('t.node_id')->distinct())->pluck('name', 'id');
        $convert = function ($r, bool $connection) use ($emails, $nodeNames) {
            $ids = $connection ? [(string) $r->node_id] : [];
            return ['key' => json_encode([(string) $r->user_id, $r->ip, $connection ? (string) $r->node_id : '']),
                'userId' => (string) $r->user_id, 'user' => $emails[$r->user_id] ?? '已删除用户 #' . $r->user_id, 'ip' => $r->ip,
                'nodeIds' => $ids, 'nodes' => array_map(fn($id) => $nodeNames[$id] ?? '已删除节点 #' . $id, $ids),
                'upload' => $r->upload / 1073741824, 'download' => $r->download / 1073741824, 'total' => $r->total / 1073741824,
                'first' => (int) $r->first_seen * 1000, 'last' => (int) $r->last_seen * 1000, 'measured' => (int) $r->measured, 'missing' => (int) $r->missing];
        };
        // Fetch node membership only for the displayed source groups, not all
        // historical samples. A shared IP belonging to different users stays separate.
        $mappedRows = $rows->map(fn($r) => $convert($r, $grouping === 'connection'))->all();
        if ($grouping === 'source' && $mappedRows) {
            $membership = (clone $eligible)->where(function ($q) use ($mappedRows) {
                foreach ($mappedRows as $r) $q->orWhere(fn($pair) => $pair->where('t.user_id', $r['userId'])->where('t.ip', $r['ip']));
            })->select('t.user_id', 't.ip', 't.node_id')->distinct()->limit(10001)->get();
            abort_if($membership->count() > 10000, 422, 'Narrow the source scope');
            foreach ($mappedRows as &$row) foreach ($membership as $m) {
                if ((string) $m->user_id === $row['userId'] && $m->ip === $row['ip']) {
                    $row['nodeIds'][] = (string) $m->node_id;
                    $row['nodes'][] = $nodeNames[$m->node_id] ?? '已删除节点 #' . $m->node_id;
                }
            }
            unset($row);
        }
        return ['rows' => $mappedRows, 'sources' => $sources->map(fn($r) => $convert($r, false))->all(), 'trend' => $trend,
            'total_rows' => $totalRows, 'page' => $page, 'per_page' => $perPage,
            'summary' => ['total' => $summary->total / 1073741824, 'measured' => (int) $summary->measured,
                'completeSamples' => max(0, (int) $summary->measured - (int) $summary->gaps),
                'totalSamples' => (int) $summary->measured + (int) $summary->missing - (int) $summary->gaps,
                'missing' => (int) $summary->missing, 'ips' => $summary->ips, 'users' => $summary->users, 'nodes' => $summary->nodes, 'sources' => $sourceCount],
            'granularity' => $grain === 3600 ? 'hour' : 'day', 'collection_complete' => $complete];
    }
}
