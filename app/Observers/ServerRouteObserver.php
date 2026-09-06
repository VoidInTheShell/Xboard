<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerRoute;

class ServerRouteObserver
{
    public bool $afterCommit = true;

    public function updated(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    public function deleted(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    private function notifyAffectedNodes(int $routeId): void
    {
        $servers = Server::whereNotNull('route_ids')->get()->filter(
            fn ($s) => in_array($routeId, $s->route_ids ?? [])
        );

        foreach ($servers as $server) {
            // Route definitions are part of the node's delivered runtime
            // configuration.  Advance the same revision used by native
            // Xray updates; ServerObserver performs the push and any live
            // outbound dependency cascade after this quiet write.
            $server->config_revision = (int) ($server->config_revision ?? 0) + 1;
            $server->save();
        }
    }
}
