<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandaloneAdminRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_saving_a_new_admin_path_immediately_moves_the_admin_api_to_that_path(): void
    {
        $oldPath = 'unitedearthgov';
        $newPath = 'standalone-admin-next';
        admin_setting(['secure_path' => $oldPath]);
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/v2/{$oldPath}/config/save", [
            'secure_path' => $newPath,
        ])->assertOk()->assertJson(['data' => true]);

        $this->getJson("/api/v2/{$newPath}/config/fetch?key=safe")
            ->assertOk()
            ->assertJsonPath('data.safe.secure_path', $newPath);

        $this->getJson("/api/v2/{$oldPath}/config/fetch?key=safe")
            ->assertNotFound();
    }

    public function test_internal_routes_require_the_router_token_and_return_the_current_path_and_legacy_panel(): void
    {
        $tokenPath = tempnam(sys_get_temp_dir(), 'xboard-admin-route-');
        file_put_contents($tokenPath, 'test-admin-route-token');
        putenv("ADMIN_ROUTE_TOKEN_FILE={$tokenPath}");
        admin_setting(['secure_path' => 'unitedearthgov']);

        try {
            $this->get('/_internal/standalone-admin/entry')->assertForbidden();

            $headers = ['X-Xboard-Admin-Route-Token' => 'test-admin-route-token'];
            $this->withHeaders($headers)
                ->get('/_internal/standalone-admin/entry')
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('unitedearthgov');

            $this->withHeaders($headers)
                ->get('/_internal/standalone-admin/original')
                ->assertOk()
                ->assertSee('<title>XBoard</title>', false);
        } finally {
            putenv('ADMIN_ROUTE_TOKEN_FILE');
            if (is_file($tokenPath)) {
                unlink($tokenPath);
            }
        }
    }

    public function test_reserved_passport_path_cannot_be_saved_as_an_admin_path(): void
    {
        admin_setting(['secure_path' => 'unitedearthgov']);
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/v2/unitedearthgov/config/save', [
            'secure_path' => 'passport',
        ])->assertUnprocessable()->assertJsonValidationErrors('secure_path');
    }

    public function test_v2_passport_route_is_not_captured_by_the_dynamic_admin_group(): void
    {
        $route = $this->app['router']->getRoutes()->match(
            Request::create('/api/v2/passport/auth/login', 'POST')
        );

        $this->assertSame(AuthController::class . '@login', $route->getActionName());
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => true,
            'is_staff' => false,
        ]);
    }
}
