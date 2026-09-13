<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            $router->group(['prefix' => 'usage', 'middleware' => 'throttle:60,1'], function ($router) {
                foreach (['snapshot', 'events', 'leaderboard'] as $action) {
                    $router->get('/' . $action, [\App\Http\Controllers\V1\User\UsageController::class, $action]);
                }
                $router->post('/visit', [\App\Http\Controllers\V1\User\UsageController::class, 'visit']);
                $router->post('/review', [\App\Http\Controllers\V1\User\UsageController::class, 'review']);
            });
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
        });
    }
}
