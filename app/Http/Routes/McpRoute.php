<?php

namespace App\Http\Routes;

use App\Http\Controllers\McpServerController;
use Illuminate\Contracts\Routing\Registrar;

class McpRoute
{
    public function map(Registrar $router): void
    {
        $router->post('/mcp', [McpServerController::class, 'handle'])
            ->middleware(['mcp.auth', 'throttle:120,1']);
        $router->options('/mcp', fn() => response('', 204));
    }
}
