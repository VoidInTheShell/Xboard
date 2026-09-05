<?php

namespace App\Services;

use App\Models\ClientApp;
use App\Models\ClientAppRecommendation;
use App\Models\ClientAppScope;
use App\Support\ClientCatalogDefaults;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ClientCatalogService
{
    public const TEMPLATES = ['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard'];

    public const DEVICE_PLATFORMS = [
        'desktop' => ['windows', 'mac-intel', 'mac-apple-silicon', 'linux'],
        'mobile' => ['ios', 'android'],
    ];

    private const INITIALIZED_SETTING = 'client_catalog_initialized';

    private const TEMPLATE_FLAGS = [
        'singbox' => 'sing-box',
        'clash' => 'clash',
        'clashmeta' => 'meta',
        'stash' => 'stash',
        'surge' => 'surge',
        'surfboard' => 'surfboard',
    ];

    public function listForAdmin(): array
    {
        $this->ensureDefaults();
        $defaultScopeIds = $this->effectiveDefaultScopeIds();

        return ClientApp::with(['scopes' => fn($query) => $query
            ->orderBy('device_type')
            ->orderBy('platform')
            ->orderBy('sort_order')])
            ->orderBy('id')
            ->get()
            ->map(fn(ClientApp $client) => $this->serialize($client, true, $defaultScopeIds))
            ->all();
    }

    public function listForUser(string $subscribeUrl): array
    {
        $this->ensureDefaults();
        $defaultScopeIds = $this->effectiveDefaultScopeIds();

        return ClientApp::with(['scopes' => fn($query) => $query
            ->orderBy('device_type')
            ->orderBy('platform')
            ->orderBy('sort_order')])
            ->where('is_enabled', true)
            ->whereHas('scopes')
            ->orderBy('id')
            ->get()
            ->map(function (ClientApp $client) use ($subscribeUrl, $defaultScopeIds) {
                $data = $this->serialize($client, false, $defaultScopeIds);
                $data['subscription_url'] = $this->appendTemplateFlag(
                    $subscribeUrl,
                    self::TEMPLATE_FLAGS[$client->subscription_template]
                );
                return $data;
            })
            ->all();
    }

    /**
     * Return the effective recommendation for every supported device/platform
     * pair. A recommendation is always the first enabled client in the current
     * platform order when no explicit administrator choice exists.
     */
    public function platformDefaults(): array
    {
        $this->ensureDefaults();

        return collect(self::DEVICE_PLATFORMS)
            ->flatMap(fn(array $platforms, string $deviceType) => collect($platforms)
                ->map(fn(string $platform) => $this->platformDefault($deviceType, $platform)))
            ->values()
            ->all();
    }

    public function save(array $data, ?UploadedFile $logoFile = null): ClientApp
    {
        $client = isset($data['id']) ? ClientApp::findOrFail((int) $data['id']) : new ClientApp();
        $oldLogoPath = $client->logo_path;
        $newLogoPath = null;

        if ($data['logo_mode'] === 'upload' && $logoFile) {
            $newLogoPath = $logoFile->storePublicly('client-logos', 'public');
            $data['logo_path'] = $newLogoPath;
            $data['logo_url'] = null;
        } elseif ($data['logo_mode'] === 'upload') {
            if (!$oldLogoPath) {
                throw ValidationException::withMessages([
                    'logo_file' => ['请上传 PNG、JPG 或 WebP 格式的客户端 Logo。'],
                ]);
            }
            $data['logo_path'] = $oldLogoPath;
            $data['logo_url'] = null;
        } else {
            $data['logo_path'] = null;
        }

        $scopes = $data['scopes'];
        unset($data['id'], $data['scopes']);

        if (!$client->exists) {
            $data['slug'] = $this->uniqueSlug($data['name']);
            $data['is_builtin'] = false;
        }

        try {
            DB::transaction(function () use ($client, $data, $scopes): void {
                $client->fill($data);
                $client->save();
                $this->syncScopes($client, $scopes);
            });
        } catch (Throwable $error) {
            if ($newLogoPath) {
                Storage::disk('public')->delete($newLogoPath);
            }
            throw $error;
        }

        $this->reconcileRecommendations();

        if ($oldLogoPath && $oldLogoPath !== $client->logo_path) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return $client->fresh('scopes');
    }

    public function delete(int $id): void
    {
        $logoPath = DB::transaction(function () use ($id): ?string {
            $client = ClientApp::findOrFail($id);
            $logoPath = $client->logo_path;
            $client->delete();

            return $logoPath;
        });

        $this->reconcileRecommendations();

        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }
    }

    public function reorder(string $deviceType, string $platform, array $ids): void
    {
        $submittedIds = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($deviceType, $platform, $submittedIds): void {
            $scopes = ClientAppScope::where('device_type', $deviceType)
                ->where('platform', $platform)
                ->lockForUpdate()
                ->get()
                ->keyBy('client_app_id');

            $existingIds = $scopes->keys()->map(fn($id) => (int) $id)->values()->all();
            $expected = $existingIds;
            $actual = $submittedIds;
            sort($expected);
            sort($actual);

            if ($expected !== $actual) {
                throw ValidationException::withMessages([
                    'ids' => ['排序列表必须完整包含当前设备与平台分类中的客户端。'],
                ]);
            }

            foreach ($submittedIds as $index => $clientId) {
                $scopes->get($clientId)?->update(['sort_order' => ($index + 1) * 10]);
            }
        });
    }

    public function serialize(ClientApp $client, bool $admin, ?array $defaultScopeIds = null): array
    {
        $defaultScopeIds ??= $this->effectiveDefaultScopeIds();
        $scopes = $client->relationLoaded('scopes') ? $client->scopes : $client->scopes()->get();
        $data = [
            'id' => $client->id,
            'slug' => $client->slug,
            'name' => $client->name,
            'description' => $client->description,
            'logo_mode' => $client->logo_mode,
            'logo_url' => $this->publicLogoUrl($client),
            'tags' => array_values($client->tags ?? []),
            'is_enabled' => (bool) $client->is_enabled,
            'download_url' => $client->download_url,
            'docs_url' => $client->docs_url,
            'quick_import_enabled' => (bool) $client->quick_import_enabled,
            'quick_import_url' => $client->quick_import_url,
            'subscription_template' => $client->subscription_template,
            'scopes' => $scopes->map(fn(ClientAppScope $scope) => [
                'device_type' => $scope->device_type,
                'platform' => $scope->platform,
                'sort_order' => (int) $scope->sort_order,
                'is_default' => ($defaultScopeIds[$scope->device_type . ':' . $scope->platform] ?? null) === (int) $scope->id,
            ])->values()->all(),
        ];

        if ($admin) {
            $data['is_builtin'] = (bool) $client->is_builtin;
            $data['has_uploaded_logo'] = (bool) $client->logo_path;
        }

        return $data;
    }

    public function ensureDefaults(): void
    {
        $createdDefaults = false;

        if (!ClientApp::query()->exists() && !DB::table('v2_settings')->where('name', self::INITIALIZED_SETTING)->exists()) {
            DB::transaction(function () use (&$createdDefaults): void {
                if (ClientApp::query()->exists()) {
                    return;
                }

                $claimed = DB::table('v2_settings')->insertOrIgnore([
                    'name' => self::INITIALIZED_SETTING,
                    'value' => '1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($claimed === 0) {
                    return;
                }

                foreach (ClientCatalogDefaults::all() as $definition) {
                    $scopes = $definition['scopes'];
                    unset($definition['scopes']);
                    $client = ClientApp::create($definition);
                    $client->scopes()->createMany($scopes);
                }
                $createdDefaults = true;
            });
        }

        if ($createdDefaults) {
            $this->reconcileRecommendations();
        }
    }

    /**
     * Persist the administrator-selected client for a platform. Passing null
     * intentionally clears the explicit choice and stores the current order's
     * first enabled client as the deterministic fallback.
     */
    public function setDefault(string $deviceType, string $platform, ?int $clientId): array
    {
        $this->ensureDefaults();

        DB::transaction(function () use ($deviceType, $platform, $clientId): void {
            $selected = $clientId === null
                ? $this->eligibleScopeQuery($deviceType, $platform)->first()
                : ClientAppScope::query()
                    ->where('client_app_id', $clientId)
                    ->where('device_type', $deviceType)
                    ->where('platform', $platform)
                    ->whereHas('client', fn($query) => $query->where('is_enabled', true))
                    ->first();

            if ($clientId !== null && !$selected) {
                throw ValidationException::withMessages([
                    'client_app_id' => ['所选客户端未启用或不属于当前设备与系统平台。'],
                ]);
            }

            $recommendation = ClientAppRecommendation::query()
                ->where('device_type', $deviceType)
                ->where('platform', $platform)
                ->lockForUpdate()
                ->first();

            if (!$selected) {
                $recommendation?->delete();
                return;
            }

            if ($recommendation) {
                $recommendation->update([
                    'client_app_scope_id' => $selected->id,
                    'is_manual' => $clientId !== null,
                ]);
            } else {
                ClientAppRecommendation::create([
                    'client_app_scope_id' => $selected->id,
                    'device_type' => $deviceType,
                    'platform' => $platform,
                    'is_manual' => $clientId !== null,
                ]);
            }
        });

        $this->reconcileRecommendations();

        return $this->platformDefault($deviceType, $platform);
    }

    private function reconcileRecommendations(): void
    {
        foreach (self::DEVICE_PLATFORMS as $deviceType => $platforms) {
            foreach ($platforms as $platform) {
                $this->reconcileRecommendation($deviceType, $platform);
            }
        }
    }

    private function reconcileRecommendation(string $deviceType, string $platform): void
    {
        DB::transaction(function () use ($deviceType, $platform): void {
            $recommendation = ClientAppRecommendation::query()
                ->where('device_type', $deviceType)
                ->where('platform', $platform)
                ->lockForUpdate()
                ->first();
            $selected = $recommendation?->scope()->with('client')->first();
            $isValid = $selected
                && $selected->device_type === $deviceType
                && $selected->platform === $platform
                && $selected->client
                && (bool) $selected->client->is_enabled;

            if ($isValid) {
                return;
            }

            $fallback = $this->eligibleScopeQuery($deviceType, $platform)->first();
            if (!$fallback) {
                $recommendation?->delete();
                return;
            }

            if ($recommendation) {
                $recommendation->update([
                    'client_app_scope_id' => $fallback->id,
                    'is_manual' => false,
                ]);
                return;
            }

            ClientAppRecommendation::create([
                'client_app_scope_id' => $fallback->id,
                'device_type' => $deviceType,
                'platform' => $platform,
                'is_manual' => false,
            ]);
        });
    }

    private function effectiveDefaultScopeIds(): array
    {
        return ClientAppRecommendation::query()
            ->with('scope')
            ->get()
            ->filter(fn(ClientAppRecommendation $recommendation) => $recommendation->scope)
            ->mapWithKeys(fn(ClientAppRecommendation $recommendation) => [
                $recommendation->device_type . ':' . $recommendation->platform => (int) $recommendation->client_app_scope_id,
            ])
            ->all();
    }

    private function platformDefault(string $deviceType, string $platform): array
    {
        $recommendation = ClientAppRecommendation::query()
            ->with('scope.client')
            ->where('device_type', $deviceType)
            ->where('platform', $platform)
            ->first();
        $client = $recommendation?->scope?->client;

        return [
            'device_type' => $deviceType,
            'platform' => $platform,
            'client_app_id' => $client?->id,
            'client_slug' => $client?->slug,
            'client_name' => $client?->name,
            'is_manual' => (bool) ($recommendation?->is_manual ?? false),
            'is_fallback' => !(bool) ($recommendation?->is_manual ?? false),
        ];
    }

    private function eligibleScopeQuery(string $deviceType, string $platform)
    {
        return ClientAppScope::query()
            ->where('device_type', $deviceType)
            ->where('platform', $platform)
            ->whereHas('client', fn($query) => $query->where('is_enabled', true))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function syncScopes(ClientApp $client, array $scopes): void
    {
        $existing = $client->scopes()->get()->keyBy(
            fn(ClientAppScope $scope) => $scope->device_type . ':' . $scope->platform
        );
        $keepIds = [];

        foreach ($scopes as $scopeData) {
            $key = $scopeData['device_type'] . ':' . $scopeData['platform'];
            $scope = $existing->get($key);
            if (!$scope) {
                $scope = $client->scopes()->create([
                    'device_type' => $scopeData['device_type'],
                    'platform' => $scopeData['platform'],
                    'sort_order' => ((int) ClientAppScope::where('device_type', $scopeData['device_type'])
                        ->where('platform', $scopeData['platform'])
                        ->max('sort_order')) + 10,
                ]);
            }
            $keepIds[] = $scope->id;
        }

        $client->scopes()->whereNotIn('id', $keepIds)->delete();
    }

    private function publicLogoUrl(ClientApp $client): ?string
    {
        if ($client->logo_mode !== 'upload') {
            return $client->logo_url;
        }
        if (!$client->logo_path) {
            return null;
        }

        return '/storage/' . collect(explode('/', $client->logo_path))
            ->map(fn(string $part) => rawurlencode($part))
            ->implode('/');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = Str::limit($base, 80, '');
        $candidate = $slug;
        $suffix = 2;

        while (ClientApp::where('slug', $candidate)->exists()) {
            $candidate = Str::limit($slug, 72, '') . '-' . $suffix++;
        }

        return $candidate;
    }

    private function appendTemplateFlag(string $url, string $flag): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $base, 2), 2, '');
        parse_str($query, $parameters);
        $parameters['flag'] = $flag;
        $result = $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $fragment === null ? $result : $result . '#' . $fragment;
    }
}
