<?php

namespace App\Services\Usage;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UsageQueryService
{
    public function scoped(Builder $query, array $scope, string $prefix = ''): Builder
    {
        foreach (['user_id', 'node_id', 'machine_id'] as $key) {
            if (!empty($scope[$key])) $query->where($prefix . $key, $scope[$key]);
        }
        return $query;
    }

    public function onlineCounts(): array
    {
        return Cache::remember('usage:online-counts', 10, function () {
            $base = DB::table('v2_usage_online as o')->join('v2_usage_source as s', 's.id', '=', 'o.source_id')
                ->where('o.sampled_at', '>=', time() - config('usage.online_ttl'));
            $result = ['enabled' => true];
            foreach (['node_id' => 'nodes', 'machine_id' => 'machines'] as $column => $key) {
                $result[$key] = (clone $base)->select($column)
                    ->selectRaw('COUNT(DISTINCT o.user_id) as users, COUNT(DISTINCT o.source_id) as devices, COUNT(DISTINCT s.ip) as ips, MAX(o.sampled_at) as sampled_at')
                    ->groupBy($column)->get()->keyBy($column)->all();
            }
            $nodes = DB::table('v2_server as n')->leftJoin('v2_usage_head as h', function ($join) {
                $join->on('h.source_id', '=', 'n.id')->where('h.scope', 'node');
            })->where('n.enabled', true)->get(['n.id', 'n.machine_id', 'h.sampled_at', 'h.devices_complete']);
            foreach ($nodes as $node) {
                if (!$node->devices_complete || !$node->sampled_at || $node->sampled_at < time() - config('usage.online_ttl')) {
                    unset($result['nodes'][$node->id]);
                } elseif (!isset($result['nodes'][$node->id])) {
                    $result['nodes'][$node->id] = ['users' => 0, 'devices' => 0, 'ips' => 0, 'sampled_at' => (int) $node->sampled_at];
                }
            }
            foreach ($nodes->groupBy('machine_id') as $machineId => $machineNodes) {
                if ($machineNodes->contains(fn($n) => !isset($result['nodes'][$n->id]))) unset($result['machines'][$machineId]);
                elseif (!isset($result['machines'][$machineId])) $result['machines'][$machineId] = [
                    'users' => 0, 'devices' => 0, 'ips' => 0, 'sampled_at' => (int) $machineNodes->min('sampled_at'),
                ];
            }
            return $result;
        });
    }

    public function snapshot(array $scope, int $from, int $to, bool $admin, int $actor = 0): array
    {
        $grain = $to - $from <= 86400 ? 3600 : 86400;
        $bucketExpression = "bucket - ((bucket + 28800) % $grain)";
        $trafficQuery = $this->scoped(DB::table('v2_usage_traffic')->where('layer', 'proxy'), $scope)
            ->whereBetween('bucket', [intdiv($from, 3600) * 3600, $to])
            ->select('node_id', 'machine_id')
            ->selectRaw("$bucketExpression as bucket, SUM(up) as up, SUM(down) as down, SUM(billed_up) as billed_up, SUM(billed_down) as billed_down")
            ->groupByRaw("$bucketExpression, node_id, machine_id");
        if (!empty($scope['user_id'])) $trafficQuery->selectRaw('user_id')->groupBy('user_id');
        else $trafficQuery->selectRaw('0 as user_id');
        $traffic = $trafficQuery->orderBy('bucket')->limit(10001)->get();
        $retained = collect(app(\App\Services\Logs\LogArchive::class)->query($scope,$from-($from+28800)%86400,$to))
            ->where('layer','proxy')->values();
        foreach ($retained as $row) {
            $traffic->push((object)array_replace($row,['user_id'=>!empty($scope['user_id'])?$row['user_id']:0]));
        }
        $traffic = $traffic->groupBy(fn($r)=>$r->bucket.':'.$r->node_id.':'.$r->machine_id.':'.$r->user_id)
            ->map(function ($rows) { $row=clone $rows->first(); foreach (['up','down','billed_up','billed_down'] as $key) $row->$key=$rows->sum($key); return $row; })
            ->sortBy('bucket')->values();
        // Never silently return a partial total.
        abort_if($traffic->count() > 10000, 422, 'Select a shorter range or narrower user/node scope');
        $online = $this->scoped(DB::table('v2_usage_online as o'), $scope, 'o.')
            ->where('o.sampled_at', '>=', time() - 600)
            ->join('v2_usage_source as s', 's.id', '=', 'o.source_id')
            ->join('v2_user as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('v2_server as n', 'n.id', '=', 'o.node_id')
            ->leftJoin('v2_server_machine as m', 'm.id', '=', 'o.machine_id')
            ->select('o.*', 's.ip', 's.first_seen', 'u.email', 'n.name as node_name', 'm.name as machine_name')
            ->orderByDesc('o.sampled_at')->limit(2001)->get();
        abort_if($online->count() > 2000, 422, 'Narrow the online device scope');
        $historyScope = 'global';
        $historyId = 0;
        foreach (['user_id' => 'user', 'node_id' => 'node', 'machine_id' => 'machine'] as $key => $value) {
            if (!empty($scope[$key])) { $historyScope = $value; $historyId = $scope[$key]; break; }
        }
        $history = DB::table('v2_usage_online_history')->where(['scope' => $historyScope, 'scope_id' => $historyId])
            ->whereBetween('bucket', [intdiv($from, 3600) * 3600, $to])->orderBy('bucket')->get();
        if (count(array_filter($scope)) > 1) {
            $history = DB::table('v2_usage_online_scope_history')
                ->where(['user_id' => $scope['user_id'] ?? 0, 'node_id' => $scope['node_id'] ?? 0, 'machine_id' => $scope['machine_id'] ?? 0])
                ->whereBetween('bucket', [intdiv($from, 3600) * 3600, $to])->orderBy('bucket')->get(['bucket', 'users', 'devices']);
        }
        $nodes = Server::query();
        if (!$admin) {
            $nodes->where(fn($q)=>$q->whereIn('id', DB::table('v2_usage_traffic')->where('user_id', $scope['user_id'])->select('node_id')->distinct())
                ->orWhereIn('id',$retained->pluck('node_id')->unique()));
        }
        $subscriptionQuery = DB::table('v2_usage_event')->where('kind', 'subscription')->whereBetween('recorded_at', [$from, $to]);
        if (!empty($scope['user_id'])) $subscriptionQuery->where('user_id', $scope['user_id']);
        if (!empty($scope['machine_id']) || !empty($scope['node_id'])) $subscriptionQuery->whereRaw('1 = 0');
        $day = 'recorded_at - ((recorded_at + 28800) % 86400)';
        $subscriptionDays = (clone $subscriptionQuery)->selectRaw("$day as day, result, COUNT(*) as count")->groupByRaw("$day, result")->get();
        $subscriptionPlatforms = (clone $subscriptionQuery)->select('platform')->selectRaw('COUNT(*) as count')->groupBy('platform')->get();
        $subscriptionStats = (clone $subscriptionQuery)->selectRaw('COUNT(*) as pulls, COUNT(DISTINCT ip) as ips')->first();
        $ranks = [];
        $rankingBase = $this->scoped(DB::table('v2_usage_traffic as t')->where('t.layer', 'proxy'), $scope, 't.')
            ->whereBetween('t.bucket', [intdiv($from, 3600) * 3600, $to]);
        foreach (['user' => ['user_id', 'v2_user', 'email'], 'node' => ['node_id', 'v2_server', 'name'], 'server' => ['machine_id', 'v2_server_machine', 'name']] as $kind => [$column, $table, $label]) {
            if (!$admin && $kind !== 'node') continue;
            $totals = (clone $rankingBase)->select("t.$column as id")->selectRaw('SUM(t.up + t.down) as value')->groupBy("t.$column");
            $values=$totals->limit(10001)->get()->keyBy('id')->map(fn($r)=>(int)$r->value);
            abort_if($values->count()>10000,422,'Narrow the ranking scope');
            foreach ($retained as $row) $values[$row[$column]]=($values[$row[$column]]??0)+$row['up']+$row['down'];
            $top=$values->sortDesc()->take(6);
            $names=DB::table($table)->whereIn('id',$top->keys())->pluck($label,'id');
            $ranks[$kind]=$top->map(fn($value,$id)=>['name'=>$names[$id]??'#'.$id,'value'=>$value/1073741824])->values()->all();
        }
        $security = app(UsageSecurityService::class)->snapshot($scope, $from, $to, $admin, $actor);
        return [
            'enabled' => true, 'sampledAt' => time() * 1000,
            'devices' => $online->map(fn($d) => [
                'id' => (string) $d->node_id . ':' . $d->source_id,
                'userId' => (string) $d->user_id, 'user' => $d->email,
                'platform' => '未识别', 'kind' => 'unknown', 'client' => '未识别',
                'ip' => $d->ip, 'location' => '未知',
                'nodeId' => (string) $d->node_id, 'node' => $d->node_name ?? '已移除节点',
                'serverId' => (string) $d->machine_id, 'server' => $admin ? ($d->machine_name ?? '未绑定服务器') : '',
                'identification' => '来源 IP（非物理设备标识）',
                'downloadRate' => $d->sampled_at < time() - 120 || $d->down_speed === null ? null : $d->down_speed / 1048576,
                'uploadRate' => $d->sampled_at < time() - 120 || $d->up_speed === null ? null : $d->up_speed / 1048576,
                'connectedAt' => $d->first_seen * 1000, 'sampledAt' => $d->sampled_at * 1000,
                'risk' => $d->first_seen >= time() - 86400 ? '新来源 IP' : null,
            ])->all(),
            'traffic' => $traffic->map(fn($r) => [
                'at' => $r->bucket * 1000, 'deviceId' => '', 'deviceKnown' => false,
                'userId' => (string) $r->user_id, 'nodeId' => (string) $r->node_id,
                'serverId' => (string) $r->machine_id, 'upload' => $r->up / 1073741824,
                'download' => $r->down / 1073741824, 'billed' => ($r->billed_up + $r->billed_down) / 1073741824,
            ])->all(),
            'onlineHistory' => $history,
            'subscriptionDays' => $subscriptionDays,
            'subscriptionPlatforms' => $subscriptionPlatforms,
            'subscriptionStats' => $subscriptionStats,
            'ranks' => $ranks,
            'nodes' => $nodes->select(['id', 'name', 'machine_id'])->limit(2000)->get()->map(fn($n) => [
                'id' => (string) $n->id, 'name' => $n->name, 'serverId' => (string) ($n->machine_id ?? 0),
                'server' => '',
            ])->all(),
            'users' => $admin ? User::query()->whereIn('id', (clone $rankingBase)->select('t.user_id')->distinct())
                ->orWhereIn('id', $online->pluck('user_id'))->orWhereIn('id',$retained->pluck('user_id'))->select('id', 'email')->limit(2000)->get() : [],
            ...$security,
            'coverage' => ['traffic_granularity' => $grain === 3600 ? 'hour' : 'day', 'device_traffic' => false,
                'identity' => 'ip', 'history_days' => \App\Services\Usage\UsageSettings::get('history_days'),
                'retained_daily' => $retained->isNotEmpty()],
        ];
    }

    public function events(array $scope, array $filters, int $from, int $to, bool $admin): array
    {
        $kind = $filters['kind'] ?? 'panel';
        if ($kind === 'connection') {
            $q = DB::table('v2_usage_source as e')->join('v2_user as u', 'u.id', '=', 'e.user_id')
                ->leftJoin('v2_server as n', 'n.id', '=', 'e.first_node_id')
                ->select('e.id', 'e.user_id', 'e.ip', 'e.first_seen as at', 'u.email', 'e.first_node_id as node_id', 'n.name as node_name')
                ->whereBetween('e.first_seen', [$from, $to]);
            if (!empty($scope['user_id'])) $q->where('e.user_id', $scope['user_id']);
            if (!empty($scope['node_id'])) $q->where('e.first_node_id', $scope['node_id']);
            if (!empty($scope['machine_id'])) $q->where('n.machine_id', $scope['machine_id']);
            if (!empty($filters['platform']) && $filters['platform'] !== 'unknown') $q->whereRaw('1 = 0');
            if (!empty($filters['result']) && $filters['result'] !== '成功') $q->whereRaw('1 = 0');
            $timeColumn = 'e.first_seen';
        } else {
            $q = DB::table('v2_usage_event as e')->join('v2_user as u', 'u.id', '=', 'e.user_id')
                ->select('e.*', 'e.recorded_at as at', 'u.email')->where('e.kind', $kind)
                ->whereBetween('e.recorded_at', [$from, $to]);
            if (!empty($scope['user_id'])) $q->where('e.user_id', $scope['user_id']);
            // Panel/subscription events do not belong to a node or machine.
            if (!empty($scope['node_id']) || !empty($scope['machine_id'])) $q->whereRaw('1 = 0');
            foreach (['platform', 'result'] as $key) {
                if (!empty($filters[$key])) $q->where('e.' . $key, $filters[$key]);
            }
            $timeColumn = 'e.recorded_at';
        }
        if (!empty($filters['search'])) {
            $needle = mb_strtolower($filters['search']);
            $q->where(fn($query) => $query->whereRaw('instr(lower(e.ip), ?) > 0', [$needle])
                ->orWhereRaw('instr(lower(u.email), ?) > 0', [$needle]));
        }
        $page = $q->orderByDesc($timeColumn)->orderByDesc('e.id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);
        return ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'data' => collect($page->items())->map(fn($r) => [
                'id' => $kind . ':' . $r->id, 'at' => $r->at * 1000, 'kind' => $kind, 'deviceId' => '',
                'userId' => (string) $r->user_id, 'user' => $r->email, 'ip' => $r->ip, 'location' => '未知',
                'nodeId' => isset($r->node_id) ? (string) $r->node_id : null, 'node' => $r->node_name ?? null,
                'platform' => $r->platform ?? 'unknown', 'client' => UsageAccessService::client($r->user_agent ?? ''),
                'userAgent' => $r->user_agent ?? '', 'result' => $r->result ?? '成功',
                'risk' => null, 'action' => $r->action ?? '首次连接', 'path' => $r->path ?? '',
            ])->all()];
    }

    public function leaderboard(string $kind, int $from, int $to, bool $period, bool $admin, int $actor, string $search, int $limit): array
    {
        // Public cache never contains emails or source records; admin cache is separate.
        $key = 'usage:rank:' . hash('sha256', json_encode([$kind, $from, intdiv($to, 60), $period, $admin, $actor, $search, $limit]));
        return Cache::remember($key, 30, function () use ($kind, $from, $to, $period, $admin, $actor, $search, $limit) {
            if ($kind === 'devices') {
                $q = DB::table('v2_usage_identity')->select('user_id as id')->selectRaw('COUNT(*) as value, 0 as upload, 0 as download');
                if ($period) $q->whereBetween('first_seen', [$from, $to]);
                $q->groupBy('user_id');
            } else {
                $idColumn = $kind === 'nodes' ? 'node_id' : 'user_id';
                $q = DB::table('v2_usage_traffic')->where('layer', 'proxy')
                    ->whereBetween('bucket', [intdiv($from, 3600) * 3600, $to])->select("$idColumn as id")
                    ->selectRaw('SUM(up + down) as value, SUM(up) as upload, SUM(down) as download')
                    ->groupBy($idColumn)->havingRaw('SUM(up + down) > 0');
            }
            $eligible = DB::query()->fromSub($q, 'totals')
                ->join($kind === 'nodes' ? 'v2_server as subject' : 'v2_user as subject', 'subject.id', '=', 'totals.id')
                ->select('totals.*')->addSelect($kind === 'nodes' ? 'subject.name as label' : 'subject.email as label');
            if ($kind === 'nodes' && !$admin) $eligible->where('subject.show', true)->where('subject.enabled', true);
            $ranked = DB::query()->fromSub($eligible, 'eligible')->select('eligible.*')->selectRaw('RANK() OVER (ORDER BY value DESC) as rank');
            $all = DB::query()->fromSub($ranked, 'r');
            $count = (clone $all)->count();
            $sum = (clone $all)->sum('value');
            $own = $kind !== 'nodes' ? (clone $all)->where('r.id', $actor)->select('r.rank', 'r.value')->first() : null;
            // Only labels needed for top rows are read, never raw identity/IP rows.
            // Public search must operate on the displayed mask, never hidden email text.
            $labelExpression = 'r.label';
            if (!$admin && $kind !== 'nodes') {
                if (DB::connection()->getDriverName() === 'mysql') {
                    $at = "LOCATE('@', r.label)";
                    $local = "SUBSTRING(r.label, 1, $at - 1)";
                    $labelExpression = "CASE WHEN $at <= 1 THEN '匿名用户' ELSE CONCAT(SUBSTRING($local, 1, LEAST(2, GREATEST(1, LENGTH($local) - 1))), '***', CASE WHEN LENGTH($local) > 3 THEN RIGHT($local, 1) ELSE '' END, SUBSTRING(r.label, $at)) END";
                } else {
                    $at = "INSTR(r.label, '@')";
                    $local = "SUBSTR(r.label, 1, $at - 1)";
                    $labelExpression = "CASE WHEN $at <= 1 THEN '匿名用户' ELSE SUBSTR($local, 1, MIN(2, MAX(1, LENGTH($local) - 1))) || '***' || CASE WHEN LENGTH($local) > 3 THEN SUBSTR($local, -1) ELSE '' END || SUBSTR(r.label, $at) END";
                }
            }
            if ($search !== '') $all->whereRaw("INSTR(LOWER($labelExpression), ?) > 0", [mb_strtolower($search)]);
            $rows = $all->select('r.*')->orderByDesc('value')->orderBy('r.id')->limit($limit)->get();
            $rows = $rows->map(function ($r) use ($kind, $admin, $actor) {
                $email = $admin || $kind === 'nodes' ? $r->label : self::maskEmail($r->label);
                $divisor = $kind === 'devices' ? 1 : 1073741824;
                return ['id' => (string) $r->id, 'label' => $email, 'rank' => (int) $r->rank,
                    'value' => $r->value / $divisor, 'upload' => $r->upload / $divisor, 'download' => $r->download / $divisor,
                    'identified' => 0, 'ipFallback' => $kind === 'devices' ? (int) $r->value : 0,
                    'isSelf' => $kind !== 'nodes' && (int) $r->id === $actor, 'hint' => ''];
            });
            return ['rows' => $rows->all(), 'participants' => $count, 'total' => $sum / ($kind === 'devices' ? 1 : 1073741824),
                'own' => $own, 'sampledAt' => time() * 1000];
        });
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if (!$at) return '匿名用户';
        $local = substr($email, 0, $at);
        return substr($local, 0, min(2, max(1, strlen($local) - 1))) . '***'
            . (strlen($local) > 3 ? substr($local, -1) : '') . substr($email, $at);
    }
}
