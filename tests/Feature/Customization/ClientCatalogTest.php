<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V1\User\ClientController as UserClientController;
use App\Http\Controllers\V2\Admin\ClientController as AdminClientController;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_administrator_fetch_seeds_editable_default_catalog(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));

        $response = $this->getJson($this->routePath(AdminClientController::class . '@fetch'))
            ->assertOk()
            ->assertJsonCount(7, 'data.clients')
            ->assertJsonPath('data.clients.0.slug', 'clash-party')
            ->assertJsonPath('data.clients.0.is_builtin', true)
            ->assertJsonPath('data.clients.0.subscription_template', 'clashmeta')
            ->assertJsonPath('data.device_platforms.desktop.0', 'windows');

        $flClash = collect($response->json('data.clients'))->firstWhere('slug', 'flclash');
        $this->assertNotNull($flClash);
        $this->assertCount(5, $flClash['scopes']);
        $this->assertDatabaseCount('v2_client_app', 7);
    }

    public function test_non_administrator_cannot_manage_client_catalog(): void
    {
        Sanctum::actingAs($this->makeUser(['is_staff' => true]));

        $this->getJson($this->routePath(AdminClientController::class . '@fetch'))
            ->assertForbidden();
    }

    public function test_administrator_can_add_and_edit_a_client(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $this->getJson($this->routePath(AdminClientController::class . '@fetch'))->assertOk();

        $payload = $this->validClientPayload();
        $created = $this->postJson($this->routePath(AdminClientController::class . '@save'), $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Test Client')
            ->assertJsonPath('data.quick_import_enabled', true)
            ->json('data');

        $this->assertFalse($created['is_builtin']);
        $this->assertDatabaseHas('v2_client_app', [
            'id' => $created['id'],
            'name' => 'Test Client',
            'subscription_template' => 'clashmeta',
        ]);
        $this->assertDatabaseHas('v2_client_app_scope', [
            'client_app_id' => $created['id'],
            'device_type' => 'desktop',
            'platform' => 'windows',
        ]);

        $payload['id'] = $created['id'];
        $payload['name'] = 'Renamed Client';
        $this->postJson($this->routePath(AdminClientController::class . '@save'), $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Client');
    }

    public function test_invalid_device_platform_pair_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $payload = $this->validClientPayload();
        $payload['scopes'] = [[
            'device_type' => 'mobile',
            'platform' => 'windows',
        ]];

        $this->postJson($this->routePath(AdminClientController::class . '@save'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scopes.0.platform']);
    }

    public function test_sorting_android_does_not_change_windows_order(): void
    {
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $fetchPath = $this->routePath(AdminClientController::class . '@fetch');
        $sortPath = $this->routePath(AdminClientController::class . '@sort');
        $clients = $this->getJson($fetchPath)->assertOk()->json('data.clients');

        $androidIds = $this->orderedIds($clients, 'mobile', 'android');
        $windowsBefore = $this->orderedIds($clients, 'desktop', 'windows');
        $this->assertGreaterThan(1, count($androidIds));

        $this->postJson($sortPath, [
            'device_type' => 'mobile',
            'platform' => 'android',
            'ids' => array_reverse($androidIds),
        ])->assertOk();

        $updated = $this->getJson($fetchPath)->assertOk()->json('data.clients');
        $this->assertSame(array_reverse($androidIds), $this->orderedIds($updated, 'mobile', 'android'));
        $this->assertSame($windowsBefore, $this->orderedIds($updated, 'desktop', 'windows'));
    }

    public function test_logo_upload_accepts_raster_image_and_rejects_svg(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));
        $payload = $this->validClientPayload();
        $payload['logo_mode'] = 'upload';
        $payload['logo_url'] = null;
        $payload['logo_file'] = UploadedFile::fake()->createWithContent(
            'logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nEAAAAAASUVORK5CYII=')
        );

        $response = $this->post($this->routePath(AdminClientController::class . '@save'), $payload)
            ->assertOk();
        $logoUrl = $response->json('data.logo_url');
        $this->assertStringStartsWith('/storage/client-logos/', $logoUrl);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $logoUrl));

        $payload['logo_file'] = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        $this->post($this->routePath(AdminClientController::class . '@save'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo_file']);
    }

    public function test_user_catalog_has_template_specific_urls_without_internal_logo_paths(): void
    {
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson($this->routePath(UserClientController::class . '@fetch'))
            ->assertOk()
            ->assertJsonCount(7, 'data.clients');
        $clients = collect($response->json('data.clients'));

        $party = $clients->firstWhere('slug', 'clash-party');
        $hiddify = $clients->firstWhere('slug', 'hiddify');
        $surfboard = $clients->firstWhere('slug', 'surfboard');
        $this->assertStringContainsString('flag=meta', $party['subscription_url']);
        $this->assertStringContainsString('flag=sing-box', $hiddify['subscription_url']);
        $this->assertStringContainsString('flag=surfboard', $surfboard['subscription_url']);
        $this->assertArrayNotHasKey('has_uploaded_logo', $party);
        $this->assertArrayNotHasKey('logo_path', $party);
    }

    private function validClientPayload(): array
    {
        return [
            'name' => 'Test Client',
            'description' => 'A test client used for catalog feature tests.',
            'logo_mode' => 'url',
            'logo_url' => 'https://example.com/client.png',
            'tags' => ['Desktop', 'Test'],
            'download_url' => 'https://example.com/download',
            'docs_url' => 'https://example.com/docs',
            'quick_import_enabled' => true,
            'quick_import_url' => 'clash://install-config?url={url}&name={name}',
            'subscription_template' => 'clashmeta',
            'scopes' => [[
                'device_type' => 'desktop',
                'platform' => 'windows',
            ]],
        ];
    }

    private function orderedIds(array $clients, string $deviceType, string $platform): array
    {
        return collect($clients)
            ->map(function (array $client) use ($deviceType, $platform) {
                $scope = collect($client['scopes'])->first(fn(array $scope) => (
                    $scope['device_type'] === $deviceType && $scope['platform'] === $platform
                ));
                return $scope ? ['id' => $client['id'], 'order' => $scope['sort_order']] : null;
            })
            ->filter()
            ->sortBy('order')
            ->pluck('id')
            ->values()
            ->all();
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
