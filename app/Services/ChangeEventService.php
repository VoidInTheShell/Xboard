<?php

namespace App\Services;

use App\Exceptions\ChangeVersionConflictException;
use App\Models\ChangeEvent;
use App\Models\McpKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

class ChangeEventService
{
    public function currentVersion(): int
    {
        return (int) (DB::table('v2_change_state')->where('id', 1)->value('version') ?? 0);
    }

    public function commitMutation(Request $request, array $operation): ChangeEvent
    {
        $state = DB::table('v2_change_state')->where('id', 1)->lockForUpdate()->first();
        $current = (int) ($state->version ?? 0);
        $expected = $request->attributes->get('mcp_expected_change_version');

        if ($expected !== null && (int) $expected !== $current) {
            throw new ChangeVersionConflictException((int) $expected, $current);
        }

        $version = $current + 1;
        DB::table('v2_change_state')->updateOrInsert(
            ['id' => 1],
            ['version' => $version, 'updated_at' => time()]
        );

        $mcpKey = $request->attributes->get('mcp_key');
        $admin = $request->user();
        $actorType = $mcpKey instanceof McpKey ? 'mcp' : 'admin';
        $actorId = $mcpKey instanceof McpKey ? $mcpKey->id : $admin?->id;
        $requestId = $this->requestId($request);

        $event = ChangeEvent::query()->create([
            'version' => $version,
            'domain' => $operation['domain'],
            'resource' => $operation['resource'],
            'resource_id' => $this->resourceId($request),
            'action' => $operation['id'],
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'client_id' => $actorType === 'admin' ? $this->clientId($request) : null,
            'request_id' => $requestId,
            'created_at' => time(),
        ]);

        if ($version % 100 === 0) {
            ChangeEvent::query()->where('version', '<', max(0, $version - 10000))->delete();
        }

        return $event;
    }

    public function assertExpectedVersion(Request $request): void
    {
        $expected = $request->attributes->get('mcp_expected_change_version');
        if ($expected === null) {
            return;
        }

        $state = DB::table('v2_change_state')->where('id', 1)->lockForUpdate()->first();
        $current = (int) ($state->version ?? 0);
        if ((int) $expected !== $current) {
            throw new ChangeVersionConflictException((int) $expected, $current);
        }
    }

    public function eventsSince(int $version, int $limit = 200): array
    {
        return ChangeEvent::query()
            ->where('version', '>', max(0, $version))
            ->orderBy('version')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn(ChangeEvent $event) => $this->serialize($event))
            ->all();
    }

    public function serialize(ChangeEvent $event): array
    {
        return [
            'version' => (int) $event->version,
            'domain' => $event->domain,
            'resource' => $event->resource,
            'resource_id' => $event->resource_id,
            'action' => $event->action,
            'actor_type' => $event->actor_type,
            'actor_id' => $event->actor_id,
            'client_id' => $event->client_id,
            'request_id' => $event->request_id,
            'created_at' => $event->created_at === null
                ? null
                : CarbonImmutable::createFromTimestampUTC((int) $event->created_at)->toIso8601String(),
        ];
    }

    public function requestId(Request $request): string
    {
        $existing = $request->attributes->get('mcp_request_id') ?: $request->header('X-Request-ID');
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $created = (string) Str::uuid();
        $request->attributes->set('mcp_request_id', $created);
        return $created;
    }

    private function clientId(Request $request): ?string
    {
        $value = $request->header('X-Xboard-Admin-Client-Id');
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : null;
    }

    private function resourceId(Request $request): ?string
    {
        foreach (['id', 'user_id', 'server_id', 'machine_id', 'order_id', 'template_id', 'code_id'] as $key) {
            $value = $request->input($key);
            if (is_scalar($value) && (string) $value !== '') {
                return Str::limit((string) $value, 128, '');
            }
        }

        $routeParameters = $request->route()?->parameters() ?? [];
        foreach ($routeParameters as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                return Str::limit((string) $value, 128, '');
            }
        }

        return null;
    }
}
