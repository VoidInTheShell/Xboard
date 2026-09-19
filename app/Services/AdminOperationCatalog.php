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
            || in_array($path, ['server/certificate/save', 'server/certificate/renew', 'server/certificate/drop'], true)
        );
        preg_match_all('/\{([^}]+)\}/', $path, $pathMatches);

        return [
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
