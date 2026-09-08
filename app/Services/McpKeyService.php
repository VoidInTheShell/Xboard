<?php

namespace App\Services;

use App\Models\McpKey;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class McpKeyService
{
    public const DOMAINS = ['infrastructure', 'accounts', 'finance', 'operations', 'system'];
    public const CLIENTS = ['codex', 'claude', 'cursor', 'vscode', 'generic'];

    public function enabled(): bool
    {
        $setting = Setting::query()->where('name', 'mcp_enabled')->first();
        if (!$setting) {
            return true;
        }

        return filter_var($setting->getRawOriginal('value'), FILTER_VALIDATE_BOOL);
    }

    public function setEnabled(bool $enabled): void
    {
        admin_setting(['mcp_enabled' => $enabled ? 1 : 0]);
    }

    public function create(User $owner, array $attributes): array
    {
        $scope = (string) ($attributes['scope'] ?? 'full');
        $domains = array_values(array_unique(array_intersect(
            self::DOMAINS,
            array_map('strval', $attributes['domains'] ?? [])
        )));

        if (!in_array($scope, ['full', 'read', 'custom'], true)) {
            throw ValidationException::withMessages(['scope' => ['权限预设无效。']]);
        }
        if ($scope === 'custom' && $domains === []) {
            throw ValidationException::withMessages(['domains' => ['自定义权限至少需要一个管理域。']]);
        }

        $secret = 'xbmcp_' . bin2hex(random_bytes(24));
        $now = time();
        $expiresAt = $this->resolveExpiry($attributes['expires_in_days'] ?? 90, $now);

        $key = McpKey::query()->create([
            'admin_id' => $owner->id,
            'name' => trim((string) ($attributes['name'] ?? 'Agent')),
            'token_hash' => hash('sha256', $secret),
            'token_prefix' => 'xbmcp_',
            'token_suffix' => strtoupper(substr($secret, -4)),
            'scope' => $scope,
            'domains' => $scope === 'custom' ? $domains : self::DOMAINS,
            'client' => in_array(($attributes['client'] ?? null), self::CLIENTS, true)
                ? $attributes['client']
                : 'generic',
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['key' => $key, 'secret' => $secret];
    }

    public function rotate(User $owner, McpKey $key): array
    {
        return DB::transaction(function () use ($owner, $key) {
            $locked = McpKey::query()->lockForUpdate()->findOrFail($key->id);
            if ((int) $locked->admin_id !== (int) $owner->id) {
                abort(403, '不能轮换其他管理员创建的 MCP Key。');
            }
            $locked->forceFill(['revoked_at' => time(), 'updated_at' => time()])->save();

            return $this->create($owner, [
                'name' => $locked->name,
                'scope' => $locked->scope,
                'domains' => $locked->domains,
                'client' => $locked->client,
                'expires_in_days' => $locked->expires_at === null
                    ? 'never'
                    : max(1, (int) ceil(((int) $locked->expires_at - time()) / 86400)),
            ]);
        });
    }

    public function revoke(User $owner, McpKey $key): McpKey
    {
        if ((int) $key->admin_id !== (int) $owner->id) {
            abort(403, '不能撤销其他管理员创建的 MCP Key。');
        }

        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => time(), 'updated_at' => time()])->save();
        }

        return $key->fresh();
    }

    public function authenticate(string $secret, Request $request): ?McpKey
    {
        if (!str_starts_with($secret, 'xbmcp_') || strlen($secret) > 128) {
            return null;
        }

        $key = McpKey::query()
            ->with('owner')
            ->where('token_hash', hash('sha256', $secret))
            ->first();
        if (!$key || !$key->isUsable() || !$key->owner || !$key->owner->is_admin) {
            return null;
        }

        $client = $this->clientName($request);
        $key->forceFill([
            'last_used_at' => time(),
            'last_ip' => $request->getClientIp(),
            'last_client' => $client,
            'updated_at' => time(),
        ])->saveQuietly();

        return $key;
    }

    public function serialize(McpKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'suffix' => $key->token_suffix,
            'scope' => $key->scope,
            'domains' => array_values($key->domains ?? []),
            'status' => $key->status(),
            'client' => $key->client,
            'created_at' => $this->formatTimestamp($key->created_at),
            'expires_at' => $this->formatTimestamp($key->expires_at),
            'revoked_at' => $this->formatTimestamp($key->revoked_at),
            'last_used_at' => $this->formatTimestamp($key->last_used_at),
            'last_ip' => $key->last_ip,
            'last_client' => $key->last_client,
        ];
    }

    private function resolveExpiry(mixed $value, int $now): ?int
    {
        if ($value === null || $value === 'never') {
            return null;
        }

        $days = (int) $value;
        if ($days < 1 || $days > 3650) {
            throw ValidationException::withMessages(['expires_in_days' => ['有效期必须在 1 到 3650 天之间，或选择永不过期。']]);
        }

        return $now + ($days * 86400);
    }

    private function clientName(Request $request): string
    {
        $payload = $request->json()->all();
        $meta = data_get($payload, 'params._meta');
        $clientInfo = is_array($meta) ? ($meta['io.modelcontextprotocol/clientInfo'] ?? null) : null;
        $name = is_array($clientInfo) ? ($clientInfo['name'] ?? null) : null;
        if (!is_string($name) || trim($name) === '') {
            $name = $request->userAgent() ?: 'unknown';
        }

        return Str::limit(preg_replace('/[^\pL\pN ._\/-]+/u', '', $name) ?: 'unknown', 96, '');
    }

    private function formatTimestamp(mixed $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::createFromTimestampUTC((int) $value)->toIso8601String();
    }
}
