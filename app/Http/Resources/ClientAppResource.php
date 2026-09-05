<?php

namespace App\Http\Resources;

use App\Services\ClientCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(ClientCatalogService::class)->serialize($this->resource, true);
    }
}
