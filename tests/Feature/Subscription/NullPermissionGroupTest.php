<?php

namespace Tests\Feature\Subscription;

use App\Http\Controllers\V1\User\ServerController;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NullPermissionGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_account_with_allowance_but_no_group_has_no_available_servers(): void
    {
        $user = $this->makeUser(['group_id' => null]);

        $this->assertFalse((new UserService())->isAvailable($user));
        $this->assertSame([], ServerService::getAvailableServers($user));
    }

    public function test_both_subscription_routes_reject_an_account_without_a_group(): void
    {
        $user = $this->makeUser(['group_id' => null]);
        $headers = ['User-Agent' => 'mihomo.party/v1.9.2 (clash.meta)'];

        $this->get(route('client.subscribe', ['token' => $user->token]) . '?flag=meta', $headers)
            ->assertForbidden();
        $this->get('/api/v1/client/subscribe?token=' . $user->token . '&flag=meta', $headers)
            ->assertForbidden();
    }

    public function test_user_server_list_returns_an_empty_list_without_a_group(): void
    {
        Sanctum::actingAs($this->makeUser(['group_id' => null]));
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getActionName() === ServerController::class . '@fetch');
        $this->assertNotNull($route);

        $this->getJson('/' . ltrim($route->uri(), '/'))
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_assigned_account_can_still_fetch_its_subscription(): void
    {
        $user = $this->makeUser();
        Server::create([
            'name' => 'subscription-regression-node',
            'type' => 'shadowsocks',
            'host' => 'node.example.test',
            'port' => '12345',
            'server_port' => '12345',
            'group_ids' => [1],
            'show' => true,
            'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ]);

        $this->assertTrue((new UserService())->isAvailable($user));
        $this->assertCount(1, ServerService::getAvailableServers($user));
        $response = $this->get(route('client.subscribe', ['token' => $user->token]) . '?flag=general')
            ->assertOk();
        $this->assertStringContainsString('ss://', base64_decode($response->getContent(), true));
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => Str::uuid() . '@example.test',
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => false,
            'is_staff' => false,
            'group_id' => 1,
            'banned' => false,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1073741824,
            'expired_at' => null,
        ], $overrides));
    }
}
