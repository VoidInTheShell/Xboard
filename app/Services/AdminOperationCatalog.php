<?php

namespace App\Services;

use App\Models\McpKey;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class AdminOperationCatalog
{
    private const DOMAIN_MAP = [
        'server' => 'infrastructure',
        'plan' => 'accounts',
        'user' => 'accounts',
        'client' => 'accounts',
        'config' => 'system',
        'subscribe-template' => 'system',
        'order' => 'finance',
        'payment' => 'finance',
        'coupon' => 'finance',
        'gift-card' => 'finance',
        'notice' => 'operations',
        'knowledge' => 'operations',
        'ticket' => 'operations',
        'mail' => 'operations',
        'system' => 'system',
        'theme' => 'system',
        'plugin' => 'system',
        'traffic-reset' => 'system',
        'stat' => 'system',
        'mcp' => 'system',
        'usage' => 'infrastructure',
        'update' => 'system',
    ];

    private const READ_ACTIONS = [
        'fetch', 'get', 'list', 'detail', 'history', 'logs', 'stats', 'statistics',
        'types', 'templates', 'codes', 'usages', 'snapshot', 'nodes', 'bindings',
        'machine',
    ];

    private const DANGEROUS_ACTIONS = [
        'drop', 'delete', 'destroy', 'batchdelete', 'resettoken', 'resetsecret', 'renew',
        'resettraffic', 'batchresettraffic', 'reset-user', 'paid', 'cancel', 'ban',
        'sendmail', 'testsendmail', 'upload', 'install', 'installcommand', 'uninstall', 'enable',
        'disable', 'upgrade', 'revoke', 'rotate',
    ];

    public function operations(): array
    {
        $operations = [];

        foreach (app('router')->getRoutes() as $route) {
            if (!$route instanceof Route || !$this->isAdminRoute($route)) {
                continue;
            }

            $descriptor = $this->describeRoute($route);
            if ($descriptor === null || str_starts_with($descriptor['path'], 'mcp/')) {
                continue;
            }

            $operations[$descriptor['id']] = $descriptor;
        }

        ksort($operations);
        return array_values($operations);
    }

    public function resolve(string $operationId): ?array
    {
        foreach ($this->operations() as $operation) {
            if (hash_equals($operation['id'], $operationId)) {
                return $operation;
            }
        }

        return null;
    }

    public function describeRequest(Request $request): ?array
    {
        $route = $request->route();
        if (!$route instanceof Route || !$this->isAdminRoute($route)) {
            return null;
        }

        return $this->describeRoute($route);
    }

    public function visibleTo(McpKey $key, array $operation): bool
    {
        if ($key->scope === 'read' && !$operation['read_only']) {
            return false;
        }

        if ($key->scope === 'custom') {
            return in_array($operation['domain'], $key->domains ?? [], true);
        }

        return true;
    }

    public function publicDescriptor(array $operation): array
    {
        return collect($operation)->except(['route_action'])->all();
    }

    private function isAdminRoute(Route $route): bool
    {
        $middleware = $route->gatherMiddleware();
        return in_array('admin', $middleware, true) && in_array('log', $middleware, true);
    }

    private function describeRoute(Route $route): ?array
    {
        $uri = ltrim($route->uri(), '/');
        if (!preg_match('#^api/v2/[^/]+/(.+)$#', $uri, $matches)) {
            return null;
        }

        $path = $matches[1];
        $method = $this->canonicalMethod($route, $path);
        $segments = explode('/', $path);
        $root = $segments[0] ?? 'system';
        $domain = self::DOMAIN_MAP[$root] ?? 'system';
        $actionSegment = strtolower((string) end($segments));
        $readOnly = $method === 'GET'
            || $this->isReadAction($actionSegment)
            || $path === 'server/certificate/validate';
        $dangerous = !$readOnly && (
            $this->isDangerousAction($actionSegment)
            || $path === 'update/tasks'
            || in_array($path, ['server/certificate/save', 'server/certificate/renew', 'server/certificate/drop', 'subscribe-template/remote/save', 'subscribe-template/remote/refresh', 'subscribe-template/remote/restore'], true)
        );
        preg_match_all('/\{([^}]+)\}/', $path, $pathMatches);

        $descriptor = [
            'id' => $this->operationId($path, $method),
            'method' => $method,
            'path' => $path,
            'domain' => $domain,
            'resource' => $this->resourceName($segments),
            'read_only' => $readOnly,
            'dangerous' => $dangerous,
            'required_confirmation' => $dangerous
                ? 'CONFIRM ' . $this->operationId($path, $method)
                : null,
            'path_parameters' => $pathMatches[1] ?? [],
            'route_action' => $route->getActionName(),
        ];

        if (str_starts_with($path, 'subscribe-template/remote/')) {
            $fields = [['name' => 'name', 'type' => 'string', 'required' => true, 'enum' => RemoteSubscribeTemplateService::NAMES]];
            if (!$readOnly) $fields[] = ['name' => 'expected_revision', 'type' => 'string', 'required' => true, 'description' => 'Use the per-template revision from remote/fetch, in addition to the MCP change version.'];
            if (in_array($actionSegment, ['history-detail', 'restore', 'drop'], true)) $fields[] = ['name' => 'id', 'type' => 'integer', 'required' => true];
            if ($actionSegment === 'pause') $fields[] = ['name' => 'interval_hours', 'type' => 'integer', 'minimum' => 1, 'maximum' => 720];
            if ($actionSegment === 'history') $fields[] = ['name' => 'page', 'type' => 'integer', 'default' => 1];
            if ($actionSegment === 'restore') $fields[] = ['name' => 'pause_auto_update', 'type' => 'boolean', 'default' => true];
            if ($actionSegment === 'save') $fields = array_merge($fields, [
                ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Public HTTP(S) raw template URL. Saving downloads and activates it only after validation.'],
                ['name' => 'auto_update', 'type' => 'boolean', 'required' => true],
                ['name' => 'interval_hours', 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 720],
            ]);
            $descriptor['request_fields'] = $fields;
            if ($actionSegment === 'pause') $descriptor['description'] = 'Disable automatic updates without fetching the remote URL.';
        }
        if ($path === 'plan/save') {
            $descriptor['description'] = 'Create or edit a plan. Method 5 resets monthly on reset_traffic_day at panel-local midnight, using month end for shorter months; editing the schedule recalculates existing users without resetting used traffic.';
            $descriptor['request_fields'] = [
                ['name' => 'id', 'type' => 'integer'],
                ['name' => 'name', 'type' => 'string', 'required' => true],
                ['name' => 'transfer_enable', 'type' => 'integer', 'required' => true, 'minimum' => 1, 'description' => 'Plan allowance in GiB.'],
                ['name' => 'reset_traffic_method', 'type' => 'integer', 'nullable' => true, 'enum' => [null, 0, 1, 2, 3, 4, 5]],
                ['name' => 'reset_traffic_day', 'type' => 'integer', 'nullable' => true, 'minimum' => 1, 'maximum' => 31, 'description' => 'Required when reset_traffic_method is 5.'],
            ];
        }
        if ($path === 'user/update') {
            $descriptor['description'] = 'Assigning plan_id inherits the plan quota, speed, device limit and permission group. Explicit quota/speed/device values override inheritance; omitted expiry, used traffic and inviter stay unchanged.';
        }
        if ($path === 'config/save') {
            $descriptor['request_fields'] = [
                ['name' => 'admin_login_image', 'type' => 'string', 'description' => 'Independent login-card image URL. Empty uses the default icon.'],
                ['name' => 'admin_login_image_width', 'type' => 'integer', 'minimum' => 32, 'maximum' => 400],
                ['name' => 'admin_login_image_height', 'type' => 'integer', 'minimum' => 32, 'maximum' => 400],
            ];
        }
        if ($path === 'config/uploadLoginImage') {
            $descriptor['description'] = 'Upload an independent login-card PNG/JPEG/WebP image (up to 8 MiB); save its returned URL with config/save.';
        }
        if ($path === 'server/manage/update') {
            $descriptor['description'] = 'Update node business metadata (name, host, port, tags, group_ids, rate, quota, timed rates) without resubmitting protocol or certificate settings. Runtime changes still validate enabled and machine_id.';
        }
        return $descriptor;
    }

    private function canonicalMethod(Route $route, string $path): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
        $segments = explode('/', $path);
        $lastSegment = strtolower((string) end($segments));

        if (in_array('GET', $methods, true) && $this->isReadAction($lastSegment)) {
            return 'GET';
        }
        if (in_array('POST', $methods, true)) {
            return 'POST';
        }

        return $methods[0] ?? 'GET';
    }

    private function isReadAction(string $action): bool
    {
        $plain = str_replace(['-', '_'], '', $action);
        if (str_starts_with($plain, 'get')) {
            return true;
        }

        return in_array($plain, array_map(fn(string $item) => str_replace(['-', '_'], '', $item), self::READ_ACTIONS), true);
    }

    private function isDangerousAction(string $action): bool
    {
        $plain = str_replace(['-', '_'], '', $action);
        return in_array($plain, array_map(fn(string $item) => str_replace(['-', '_'], '', $item), self::DANGEROUS_ACTIONS), true);
    }

    private function operationId(string $path, string $method): string
    {
        $segments = array_map(function (string $segment) {
            $segment = trim($segment, '{}');
            return Str::snake(str_replace('-', '_', $segment));
        }, explode('/', $path));

        return implode('.', $segments) . '.' . strtolower($method);
    }

    private function resourceName(array $segments): string
    {
        if (count($segments) <= 1) {
            return Str::snake($segments[0] ?? 'system');
        }

        return implode('.', array_map(
            fn(string $segment) => Str::snake(str_replace('-', '_', trim($segment, '{}'))),
            array_slice($segments, 0, -1)
        ));
    }
}
