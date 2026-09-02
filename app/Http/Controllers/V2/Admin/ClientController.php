<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ClientCatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function __construct(private readonly ClientCatalogService $catalog)
    {
    }

    public function fetch()
    {
        return $this->success([
            'clients' => $this->catalog->listForAdmin(),
            'templates' => ClientCatalogService::TEMPLATES,
            'device_platforms' => ClientCatalogService::DEVICE_PLATFORMS,
        ]);
    }

    public function save(Request $request)
    {
        $this->decodeArrayFields($request);

        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_client_app,id',
            'name' => 'required|string|max:80',
            'description' => 'required|string|max:1000',
            'logo_mode' => ['required', Rule::in(['upload', 'url'])],
            'logo_url' => 'nullable|required_if:logo_mode,url|url:http,https|max:2048',
            'logo_file' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:2048',
            'tags' => 'present|array|max:12',
            'tags.*' => 'string|max:32',
            'download_url' => 'required|url:http,https|max:2048',
            'docs_url' => 'nullable|url:http,https|max:2048',
            'quick_import_enabled' => 'required|boolean',
            'quick_import_url' => 'nullable|required_if:quick_import_enabled,true|string|max:2048',
            'subscription_template' => ['required', Rule::in(ClientCatalogService::TEMPLATES)],
            'scopes' => 'required|array|min:1|max:6',
            'scopes.*.device_type' => ['required', Rule::in(array_keys(ClientCatalogService::DEVICE_PLATFORMS))],
            'scopes.*.platform' => 'required|string|max:32',
        ]);

        $data['quick_import_enabled'] = (bool) $data['quick_import_enabled'];
        $data['tags'] = array_values(array_filter(array_unique(array_map('trim', $data['tags'])), fn($tag) => $tag !== ''));
        $data['quick_import_url'] = $data['quick_import_enabled']
            ? trim((string) $data['quick_import_url'])
            : null;
        $this->validateScopes($data['scopes']);
        $this->validateQuickImport($data);

        $client = $this->catalog->save($data, $request->file('logo_file'));

        return $this->success($this->catalog->serialize($client, true));
    }

    public function drop(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_client_app,id',
        ]);

        $this->catalog->delete((int) $data['id']);

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $data = $request->validate([
            'device_type' => ['required', Rule::in(array_keys(ClientCatalogService::DEVICE_PLATFORMS))],
            'platform' => 'required|string|max:32',
            'ids' => 'present|array',
            'ids.*' => 'integer|distinct|exists:v2_client_app,id',
        ]);

        $this->validateDevicePlatform($data['device_type'], $data['platform'], 'platform');
        $this->catalog->reorder($data['device_type'], $data['platform'], $data['ids']);

        return $this->success(true);
    }

    private function decodeArrayFields(Request $request): void
    {
        foreach (['tags', 'scopes'] as $field) {
            $value = $request->input($field);
            if (!is_string($value)) {
                continue;
            }
            $decoded = json_decode($value, true);
            $request->merge([$field => is_array($decoded) ? $decoded : null]);
        }
    }

    private function validateScopes(array $scopes): void
    {
        $seen = [];
        foreach ($scopes as $index => $scope) {
            $this->validateDevicePlatform(
                $scope['device_type'],
                $scope['platform'],
                "scopes.{$index}.platform"
            );
            $key = $scope['device_type'] . ':' . $scope['platform'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "scopes.{$index}.platform" => ['同一设备类型与系统平台不能重复选择。'],
                ]);
            }
            $seen[$key] = true;
        }
    }

    private function validateDevicePlatform(string $deviceType, string $platform, string $field): void
    {
        if (!in_array($platform, ClientCatalogService::DEVICE_PLATFORMS[$deviceType] ?? [], true)) {
            throw ValidationException::withMessages([
                $field => ['所选系统平台不属于该设备类型。'],
            ]);
        }
    }

    private function validateQuickImport(array $data): void
    {
        if (!$data['quick_import_enabled']) {
            return;
        }

        $url = trim((string) ($data['quick_import_url'] ?? ''));
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw ValidationException::withMessages([
                'quick_import_url' => ['快速导入链接必须以有效的 http(s) 或客户端 Scheme 开头。'],
            ]);
        }
        if (!str_contains($url, '{url}') && !str_contains($url, '{base64url}')) {
            throw ValidationException::withMessages([
                'quick_import_url' => ['快速导入链接必须包含 {url} 或 {base64url} 订阅地址占位符。'],
            ]);
        }
    }
}
