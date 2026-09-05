<?php

namespace App\Http\Requests\Admin;

use App\Services\ClientCatalogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_type' => ['required', Rule::in(array_keys(ClientCatalogService::DEVICE_PLATFORMS))],
            'platform' => 'required|string|max:32',
            'client_app_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('v2_client_app', 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deviceType = $this->input('device_type');
            $platform = $this->input('platform');
            if ($deviceType && $platform && !in_array($platform, ClientCatalogService::DEVICE_PLATFORMS[$deviceType] ?? [], true)) {
                $validator->errors()->add('platform', '所选系统平台不属于该设备类型。');
            }
        });
    }
}
