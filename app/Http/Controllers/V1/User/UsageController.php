<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\Usage\UsageAccessService;
use App\Services\Usage\UsageQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageController extends Controller
{
    protected bool $adminScope = false;

    protected function actor(Request $request)
    {
        $actor = $request->attributes->get('mcp_admin') ?? $request->user('sanctum');
        abort_unless($actor && (!$this->adminScope || $actor->is_admin), 403);
        return $actor;
    }

    protected function query(Request $request): array
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'from' => 'nullable|integer|min:0', 'to' => 'nullable|integer|min:0',
            'user_id' => 'nullable|integer|min:1', 'node_id' => 'nullable|integer|min:1',
            'machine_id' => 'nullable|integer|min:1',
        ]);
        $to = $data['to'] ?? time();
        $from = $data['from'] ?? $to - 7 * 86400;
        abort_if($from > $to || $to > time() + 60 || $to - $from > config('usage.query_days') * 86400, 422, 'Invalid date range (maximum 93 days)');
        $scope = array_intersect_key($data, array_flip(['user_id', 'node_id', 'machine_id']));
        if (!$this->adminScope) {
            // Ignore any supplied user ID; machine-level data is admin-only.
            $scope['user_id'] = $actor->id;
            unset($scope['machine_id']);
        }
        return [$scope, (int) $from, (int) $to];
    }

    public function snapshot(Request $request)
    {
        [$scope, $from, $to] = $this->query($request);
        if (!\App\Services\Usage\UsageSettings::get('enabled')) return $this->success(['enabled' => false]);
        return $this->success(app(UsageQueryService::class)->snapshot($scope, $from, $to, $this->adminScope, $this->actor($request)->id));
    }

    public function events(Request $request)
    {
        [$scope, $from, $to] = $this->query($request);
        $filters = $request->validate([
            'kind' => 'nullable|in:panel,connection,subscription',
            'platform' => 'nullable|string|max:32', 'result' => 'nullable|in:成功,失败',
            'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503, 'Usage collection is disabled');
        return $this->success(app(UsageQueryService::class)->events($scope, $filters, $from, $to, $this->adminScope));
    }

    public function ip(Request $request)
    {
        [$scope, $from, $to] = $this->query($request);
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503, 'Usage collection is disabled');
        $filters = $request->validate([
            'ip' => 'nullable|ip', 'search' => 'nullable|string|max:100',
            'family' => 'nullable|in:all,v4,v6', 'coverage' => 'nullable|in:all,complete,partial',
            'grouping' => 'nullable|in:connection,source', 'sort' => 'nullable|in:total,upload,download,first,last',
            'desc' => 'nullable|boolean', 'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:200',
            'detail' => 'nullable|boolean',
        ]);
        if ($request->boolean('detail')) {
            abort_unless(!empty($filters['ip']) && !empty($scope['user_id']), 422, 'IP detail requires a user and IP');
            $filters['per_page'] = 2000;
            $filters['page'] = 1;
            $filters['grouping'] = 'connection';
        }
        return $this->success(app(\App\Services\Usage\UsageIpQueryService::class)->query($scope, $filters, $from, $to));
    }

    public function leaderboard(Request $request)
    {
        [, $from, $to] = $this->query($request);
        $data = $request->validate([
            'kind' => 'required|in:users,nodes,devices', 'period' => 'nullable|boolean',
            'search' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:50',
        ]);
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503, 'Usage collection is disabled');
        return $this->success(app(UsageQueryService::class)->leaderboard($data['kind'], $from, $to,
            $request->boolean('period'), $this->adminScope, $this->actor($request)->id,
            $data['search'] ?? '', $data['limit'] ?? 10));
    }

    public function visit(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['path' => 'required|string|max:128|regex:#^/[a-zA-Z0-9/_-]*$#']);
        app(UsageAccessService::class)->record($request, $actor->id, 'panel', '页面访问', '成功', $data['path']);
        return $this->success(true);
    }

    public function review(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['signal' => 'required|string|max:100|regex:/^[a-zA-Z0-9:_-]+$/']);
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503);
        app(\App\Services\Usage\UsageSecurityService::class)->review($actor->id, $this->adminScope, $data['signal']);
        return $this->success(true);
    }
}
