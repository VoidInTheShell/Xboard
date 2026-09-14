<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Models\Server;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\Usage\MachineTrafficService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        if ((bool) admin_setting('self_use_mode', 0) && $servers) {
            $machineByServer = Server::whereIn('id', array_column($servers, 'id'))
                ->pluck('machine_id', 'id');
            $traffic = app(MachineTrafficService::class)->summaries($machineByServer->values()->all());
            foreach ($servers as &$server) {
                $machineId = (int) ($machineByServer[$server['id']] ?? 0);
                $server['machine_traffic'] = $traffic[$machineId] ?? null;
            }
            unset($server);
        }
        $eTag = sha1(json_encode(array_map(fn ($server) => [
            'cache_key' => $server['cache_key'] ?? null,
            'machine_traffic' => $server['machine_traffic'] ?? null,
        ], $servers)));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304);
        }
        $data = NodeResource::collection($servers);
        return response([
            'data' => $data
        ])->header('ETag', "\"{$eTag}\"");
    }
}
