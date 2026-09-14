<?php

namespace App\Http\Controllers\V2\Admin;

use App\Models\ServerMachine;
use App\Services\Usage\UsageQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class UsageController extends \App\Http\Controllers\V1\User\UsageController
{
    protected bool $adminScope = true;

    public function settings(Request $request)
    {
        $this->actor($request);
        return $this->success(collect(['enabled', 'history_days', 'access_days', 'identity_days'])
            ->mapWithKeys(fn($key) => [$key => $key === 'enabled' ? (bool) \App\Services\Usage\UsageSettings::get($key) : (int) \App\Services\Usage\UsageSettings::get($key)])->all());
    }

    public function saveSettings(Request $request)
    {
        $this->actor($request);
        $data = $request->validate(['enabled' => 'required|boolean', 'history_days' => 'required|integer|min:7|max:730',
            'access_days' => 'required|integer|min:7|max:730', 'identity_days' => 'required|integer|min:7|max:730']);
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('v2_usage_ip_traffic'), 409, 'Apply usage migrations before enabling collection');
        DB::transaction(function () use ($data) {
            $policy=\App\Services\Logs\LogSettings::get(true);
            $policy['usageEnabled']=(bool)$data['enabled'];
            foreach ($policy['policies'] as &$p) {
                if (in_array($p['id'],['traffic','nic','ip','online'],true)) $p['days']=$data['history_days'];
                if (in_array($p['id'],['web','subscription'],true)) $p['days']=$data['access_days'];
                if ($p['id']==='source') $p['days']=$data['identity_days'];
            }
            unset($p);
            \App\Services\Logs\LogSettings::save($policy);
            foreach ($data as $key => $value) \App\Models\Setting::createOrUpdate('usage_' . $key, $value);
            // MCP may wrap this in its own transaction. Invalidate only once
            // the outer transaction commits; never cache uncommitted values.
            DB::afterCommit(fn() => app(\App\Support\Setting::class)->save([]));
        });
        return $this->success($data);
    }

    public function online(Request $request)
    {
        $this->actor($request);
        return $this->success(\App\Services\Usage\UsageSettings::get('enabled') ? app(UsageQueryService::class)->onlineCounts() : ['enabled' => false, 'nodes' => [], 'machines' => []]);
    }

    public function infrastructure(Request $request)
    {
        [$scope, $from, $to] = $this->query($request);
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503);
        unset($scope['user_id']);
        $grain = $to - $from <= 86400 ? 3600 : 86400;
        $bucket = "bucket - ((bucket + 28800) % $grain)";
        $rows = app(UsageQueryService::class)->scoped(DB::table('v2_usage_traffic')->whereIn('layer', ['nic', 'instance']), $scope)
            ->whereBetween('bucket', [intdiv($from, 3600) * 3600, $to])
            ->select('layer', 'machine_id', 'node_id', 'resource')->selectRaw("$bucket as bucket, SUM(up) as up, SUM(down) as down")
            ->groupByRaw("layer, machine_id, node_id, resource, $bucket")->orderBy('bucket')->limit(10001)->get();
        abort_if($rows->count() > 10000, 422, 'Narrow the date or machine scope');
        foreach (app(\App\Services\Logs\LogArchive::class)->query($scope,$from-($from+28800)%86400,$to) as $row) {
            if (in_array($row['layer'],['nic','instance'],true)) $rows->push((object)($row+['resource'=>'每日汇总']));
        }
        return $this->success($rows->map(fn($r) => [
            'at' => max($from, (int) $r->bucket) * 1000, 'serverId' => (string) $r->machine_id, 'nodeId' => (string) $r->node_id,
            'resourceId' => $r->machine_id . ':' . $r->resource,
            'name' => $r->layer === 'nic' ? preg_replace('/^(host|container):/', '', $r->resource) : $r->resource,
            'collectionScope' => $r->layer === 'nic' && preg_match('/^(host|container):/', $r->resource, $match) ? $match[1] : 'unknown',
            'layer' => $r->layer, 'incoming' => $r->up / 1073741824, 'outgoing' => $r->down / 1073741824,
        ]));
    }

    public function policy(Request $request)
    {
        $this->actor($request);
        $request->validate(['machine_id' => 'required|integer|min:1']);
        $machine = ServerMachine::findOrFail($request->integer('machine_id'));
        $policy = $machine->traffic_policy ?? self::defaultPolicy();
        [$start, $end] = self::cycle($policy);
        $usage = \App\Services\Usage\UsageSettings::get('enabled') ? DB::table('v2_usage_traffic')->where('layer', 'nic')
            ->where('machine_id', $machine->id)->whereBetween('bucket', [$start, $end - 1])
            ->selectRaw('COALESCE(SUM(up),0) as incoming, COALESCE(SUM(down),0) as outgoing')->first() : null;
        $incoming = (int) ($usage?->incoming ?? 0);
        $outgoing = (int) ($usage?->outgoing ?? 0);
        return $this->success(['policy' => $policy, 'cycle' => ['start' => $start, 'end' => $end],
            'incoming' => (string) $incoming, 'outgoing' => (string) $outgoing,
            'used' => (string) match($policy['direction']) {'upload' => $incoming, 'download' => $outgoing, default => $incoming + $outgoing}]);
    }

    public function savePolicy(Request $request)
    {
        $this->actor($request);
        $data = $request->validate([
            'machine_id' => 'required|integer|min:1', 'limit' => 'required|numeric|gt:0|max:1000000',
            'unit' => 'required|in:GiB,TiB', 'resetDay' => 'required|integer|min:1|max:31',
            'zone' => 'required|in:UTC,Asia/Shanghai,Asia/Tokyo', 'direction' => 'required|in:both,upload,download',
            'warning' => 'required|integer|min:1|max:100',
        ]);
        $machine = ServerMachine::findOrFail($data['machine_id']);
        unset($data['machine_id']);
        // Existing log middleware audits changes; saving policy never clears history.
        $machine->update(['traffic_policy' => $data]);
        return $this->success($data);
    }

    public static function defaultPolicy(): array
    {
        return ['limit' => '1', 'unit' => 'TiB', 'resetDay' => '1', 'zone' => 'Asia/Shanghai', 'direction' => 'both', 'warning' => '80'];
    }

    public static function cycle(array $policy, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($policy['zone']);
        $month = $now->startOfMonth();
        $boundary = fn($m) => $m->addDays(min((int) $policy['resetDay'], $m->daysInMonth) - 1);
        if ($now->lt($boundary($month))) $month = $month->subMonthNoOverflow();
        return [$boundary($month)->timestamp, $boundary($month->addMonthNoOverflow())->timestamp];
    }
}
