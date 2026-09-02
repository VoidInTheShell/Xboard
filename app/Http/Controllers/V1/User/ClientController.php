<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientCatalogService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(private readonly ClientCatalogService $catalog)
    {
    }

    public function fetch(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $subscribeUrl = Helper::getSubscribeUrl($user->token);

        return $this->success([
            'clients' => $this->catalog->listForUser($subscribeUrl),
            'templates' => ClientCatalogService::TEMPLATES,
            'device_platforms' => ClientCatalogService::DEVICE_PLATFORMS,
        ]);
    }
}
