<?php

namespace App\Http\Requests\Admin;

use App\Services\ClientCatalogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientCatalogSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer|exists:v2_client_app,id',
            'name' => 'required|string|max:80',
            'description' => 'required|string|max:1000',
            'logo_mode' => ['required', Rule::in(['upload', 'url'])],
            'logo_url' => 'nullable|required_if:logo_mode,url|url:http,https|max:2048',
            'logo_file' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:2048',
            'tags' => 'present|array|max:12',
            'tags.*' => 'string|max:32',
            'is_enabled' => 'sometimes|boolean',
            'download_url' => 'required|url:http,https|max:2048',
            'docs_url' => 'nullable|url:http,https|max:2048',
            'quick_import_enabled' => 'required|boolean',
            'quick_import_url' => 'nullable|required_if:quick_import_enabled,true|string|max:2048',
            'subscription_template' => ['required', Rule::in(ClientCatalogService::TEMPLATES)],
            'scopes' => 'required|array|min:1|max:6',
            'scopes.*.device_type' => ['required', Rule::in(array_keys(ClientCatalogService::DEVICE_PLATFORMS))],
            'scopes.*.platform' => 'required|string|max:32',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['tags', 'scopes'] as $field) {
            $value = $this->input($field);
            if (!is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            $this->merge([$field => is_array($decoded) ? $decoded : null]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateScopes($validator);
            $this->validateQuickImport($validator);
        });
    }

    private function validateScopes(Validator $validator): void
    {
        $scopes = $this->input('scopes', []);
        if (!is_array($scopes)) {
            return;
        }

        $seen = [];
        foreach ($scopes as $index => $scope) {
            if (!is_array($scope)) {
                continue;
            }

            $deviceType = $scope['device_type'] ?? null;
            $platform = $scope['platform'] ?? null;
            if (!$deviceType || !$platform) {
                continue;
            }

            if (!in_array($platform, ClientCatalogService::DEVICE_PLATFORMS[$deviceType] ?? [], true)) {
                $validator->errors()->add(
                    "scopes.{$index}.platform",
                    '所选系统平台不属于该设备类型。'
                );
            }

            $key = $deviceType . ':' . $platform;
            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "scopes.{$index}.platform",
                    '同一设备类型与系统平台不能重复选择。'
                );
            }
            $seen[$key] = true;
        }
    }

    private function validateQuickImport(Validator $validator): void
    {
        if (!$this->boolean('quick_import_enabled')) {
            return;
        }

        $url = trim((string) $this->input('quick_import_url', ''));
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            $validator->errors()->add(
                'quick_import_url',
                '快速导入链接必须以有效的 http(s) 或客户端 Scheme 开头。'
            );
        }
        if (!str_contains($url, '{url}') && !str_contains($url, '{base64url}')) {
            $validator->errors()->add(
                'quick_import_url',
                '快速导入链接必须包含 {url} 或 {base64url} 订阅地址占位符。'
            );
        }
    }
}
