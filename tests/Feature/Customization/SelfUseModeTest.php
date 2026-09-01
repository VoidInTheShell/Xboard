<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V1\User\CommController as UserCommController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SelfUseModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_administrator_can_enable_and_fetch_self_use_mode(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));

        $this->postJson($this->routePath(ConfigController::class . '@save'), [
            'self_use_mode' => true,
        ])->assertOk()->assertJson(['data' => true]);

        $this->getJson($this->routePath(ConfigController::class . '@fetch') . '?key=frontend')
            ->assertOk()
            ->assertJsonPath('data.frontend.self_use_mode', true);

        $this->assertDatabaseHas('v2_settings', [
            'name' => 'self_use_mode',
            'value' => '1',
        ]);
    }

    public function test_staff_cannot_access_administrator_frontend_configuration(): void
    {
        Sanctum::actingAs($this->makeUser([
            'is_admin' => false,
            'is_staff' => true,
        ]));

        $this->getJson($this->routePath(ConfigController::class . '@fetch') . '?key=frontend')
            ->assertForbidden();
    }

    public function test_user_apis_expose_self_use_mode_and_role_flags(): void
    {
        admin_setting(['self_use_mode' => true]);
        Sanctum::actingAs($this->makeUser());

        $this->getJson($this->routePath(UserCommController::class . '@config'))
            ->assertOk()
            ->assertJsonPath('data.self_use_mode', 1);

        $this->getJson($this->routePath(UserController::class . '@info'))
            ->assertOk()
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.is_staff', false);
    }

    public function test_machine_install_command_uses_the_forked_node_installer(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'test-machine-token',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            $this->routePath(MachineController::class . '@installCommand') . '?id=' . $machine->id
        )->assertOk();

        $command = $response->json('data.command');
        $this->assertStringContainsString(
            'https://raw.githubusercontent.com/VoidInTheShell/Xboard-Node/dev/install.sh',
            $command
        );
        $this->assertStringNotContainsString('cedar2025/xboard-node', $command);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => false,
            'is_staff' => false,
        ], $attributes));
    }

    private function routePath(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === $action) {
                return '/' . ltrim($route->uri(), '/');
            }
        }

        $this->fail("Route not found for {$action}");
    }
}
