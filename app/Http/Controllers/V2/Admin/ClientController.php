<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientCatalogSaveRequest;
use App\Http\Requests\Admin\ClientDefaultRequest;
use App\Http\Resources\ClientAppResource;
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
            'platform_defaults' => $this->catalog->platformDefaults(),
            'templates' => ClientCatalogService::TEMPLATES,
            'device_platforms' => ClientCatalogService::DEVICE_PLATFORMS,
        ]);
    }

    public function save(ClientCatalogSaveRequest $request)
    {
        $data = $request->validated();

        $data['quick_import_enabled'] = (bool) $data['quick_import_enabled'];
        $data['tags'] = array_values(array_filter(array_unique(array_map('trim', $data['tags'])), fn($tag) => $tag !== ''));
        $data['quick_import_url'] = $data['quick_import_enabled']
            ? trim((string) $data['quick_import_url'])
            : null;

        $client = $this->catalog->save($data, $request->file('logo_file'));

        return $this->success((new ClientAppResource($client))->toArray($request));
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

    public function setDefault(ClientDefaultRequest $request)
    {
        $data = $request->validated();

        return $this->success($this->catalog->setDefault(
            $data['device_type'],
            $data['platform'],
            $data['client_app_id'] === null ? null : (int) $data['client_app_id'],
        ));
    }

    private function validateDevicePlatform(string $deviceType, string $platform, string $field): void
    {
        if (!in_array($platform, ClientCatalogService::DEVICE_PLATFORMS[$deviceType] ?? [], true)) {
            throw ValidationException::withMessages([
                $field => ['所选系统平台不属于该设备类型。'],
            ]);
        }
    }
}
