<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V1\User\CommController as UserCommController;
use App\Http\Controllers\V1\Guest\PlanController as GuestPlanController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\OrderController as UserOrderController;
use App\Http\Controllers\V1\User\PlanController as UserPlanController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use App\Models\User;
use App\Services\Updates\ReleaseCatalog;
use App\Exceptions\ApiException;
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

    public function test_self_use_mode_blocks_regular_purchase_apis_but_preserves_staff_access(): void
    {
        admin_setting(['self_use_mode' => true]);

        Sanctum::actingAs($this->makeUser());
        $this->getJson($this->routePath(UserPlanController::class . '@fetch'))
            ->assertForbidden();
        $this->getJson($this->routePath(GuestPlanController::class . '@fetch'))
            ->assertForbidden();
        $this->getJson($this->routePath(UserOrderController::class . '@fetch'))
            ->assertForbidden();
        $this->postJson($this->routePath(UserOrderController::class . '@save'), [])
            ->assertForbidden();

        foreach ([['is_admin' => true], ['is_staff' => true]] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->getJson($this->routePath(UserPlanController::class . '@fetch'))
                ->assertOk();
            $this->getJson($this->routePath(GuestPlanController::class . '@fetch'))
                ->assertOk();
            $this->getJson($this->routePath(UserOrderController::class . '@fetch'))
                ->assertOk();
        }
    }

    public function test_self_use_mode_blocks_invite_and_commission_apis_for_regular_users(): void
    {
        admin_setting(['self_use_mode' => true]);

        Sanctum::actingAs($this->makeUser());
        $this->getJson($this->routePath(InviteController::class . '@fetch'))
            ->assertForbidden();
        $this->getJson($this->routePath(InviteController::class . '@save'))
            ->assertForbidden();
        $this->getJson($this->routePath(InviteController::class . '@details'))
            ->assertForbidden();
        $this->postJson($this->routePath(UserController::class . '@transfer'), [
            'transfer_amount' => 100,
        ])->assertForbidden();
        $this->postJson($this->routePath(TicketController::class . '@withdraw'), [
            'withdraw_method' => 'alipay',
            'withdraw_account' => 'test@example.com',
        ])->assertForbidden();

        Sanctum::actingAs($this->makeUser(['is_staff' => true]));
        $this->getJson($this->routePath(InviteController::class . '@fetch'))
            ->assertOk();
    }

    public function test_machine_install_command_uses_the_forked_node_installer(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $this->mock(ReleaseCatalog::class, function ($catalog) {
            $catalog->shouldReceive('exact')->with('xboard-node', 'v1.14.0-dev.1234.1')
                ->andReturn(['component' => 'xboard-node', 'version' => 'v1.14.0-dev.1234.1', 'channel' => 'dev']);
        });
        UpdateExecutor::create([
            'id' => (string) Str::uuid(),
            'name' => 'panel-updater',
            'kind' => 'panel',
            'scope' => 'panel',
            'secret_hash' => hash('sha256', 'panel-updater-secret'),
            'enabled' => true,
            'blocked' => false,
            'protocol' => 2,
            'state_schema' => 1,
            'updater_version' => 'v0.2.0',
            'installation_method' => 'compose',
            'last_seen_at' => now(),
        ]);
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'test-machine-token',
            'is_active' => true,
        ]);

        $response = $this->postJson($this->routePath(MachineController::class . '@installCommand'), [
            'id' => $machine->id,
            'version' => 'v1.14.0-dev.1234.1',
            'mode' => 'compose',
        ])->assertOk();

        $command = $response->json('data.command');
        $this->assertStringContainsString(
            'https://github.com/VoidInTheShell/Xboard-Node/releases/download/v1.14.0-dev.1234.1/install.sh',
            $command
        );
        $this->assertStringNotContainsString('cedar2025/xboard-node', $command);
        $this->assertStringContainsString('--enrollment-token', $command);
        $this->assertStringNotContainsString('test-machine-token', $command);
        $this->assertStringContainsString('--updater-version \'v0.2.0\'', $command);

        $pinned = $this->postJson($this->routePath(MachineController::class . '@installCommand'), [
            'id' => $machine->id,
            'version' => 'v1.14.0-dev.1234.1',
            'mode' => 'systemd',
        ])->assertOk()->json('data.command');
        $this->assertStringContainsString(
            'https://github.com/VoidInTheShell/Xboard-Node/releases/download/v1.14.0-dev.1234.1/install.sh',
            $pinned
        );
        $this->assertStringNotContainsString('/releases/latest/', $pinned);
    }

    public function test_machine_install_command_rejects_unknown_versions(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $this->mock(ReleaseCatalog::class, function ($catalog) {
            $catalog->shouldReceive('exact')->andThrow(new ApiException('版本发布不完整或清单不符合更新协议。', 422));
        });
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'test-machine-token',
            'is_active' => true,
        ]);

        $this->postJson($this->routePath(MachineController::class . '@installCommand'), [
            'id' => $machine->id,
            'version' => 'v9.9.9-dev.1.1',
            'mode' => 'compose',
        ])->assertStatus(422);
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
