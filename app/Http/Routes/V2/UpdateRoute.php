<?php
namespace App\Http\Routes\V2;
use App\Http\Controllers\V2\UpdateExecutorController;
use Illuminate\Contracts\Routing\Registrar;

class UpdateRoute
{
    public function map(Registrar $router)
    {
        $router->group(['prefix' => 'update-executor', 'middleware' => 'throttle:120,1'], function ($route) {
            foreach (['heartbeat', 'claim', 'report'] as $action) $route->post($action, [UpdateExecutorController::class, $action]);
        });
    }
}
