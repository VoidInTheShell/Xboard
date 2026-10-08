<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\CertificateController;
use App\Models\User;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\AdminOperationCatalog;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
        Sanctum::actingAs(User::create(['email' => Str::uuid().'@example.test', 'password' => 'test', 'uuid' => Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    public function test_business_metadata_preserves_legacy_hy2_protocol_certificate_and_routes(): void
    {
        $node = $this->legacyNode()->fresh();
        $before = $node->getRawOriginal();
        $this->postJson($this->path(ManageController::class.'@update'), [
            'id' => $node->id, 'tags' => ['日本', 'hy2', '标签二'], 'name' => 'updated',
            'rate' => 1.5, 'transfer_enable' => 1024, 'group_ids' => [],
        ])->assertOk();
        $node->refresh();
        $this->assertSame(['日本', 'hy2', '标签二'], $node->tags);
        foreach (['protocol_settings', 'cert_config', 'custom_routes', 'enabled', 'machine_id', 'certificate_id'] as $field) {
            $this->assertSame($before[$field], $node->getRawOriginal($field), $field);
        }
        $this->postJson($this->path(ManageController::class.'@update'), ['id' => $node->id, 'tags' => [['invalid']]])->assertUnprocessable();
        $this->postJson($this->path(ManageController::class.'@update'), ['id' => $node->id, 'tags' => []])->assertOk();
        $this->assertSame([], $node->fresh()->tags);
    }

    public function test_legacy_path_inventory_deduplicates_without_writes_or_secret_values(): void
    {
        $node = $this->legacyNode()->fresh();
        $copy = $node->replicate(); $copy->name = 'second inbound'; $copy->saveQuietly();
        $before = $node->getRawOriginal();
        $response = $this->getJson($this->path(CertificateController::class.'@fetch').'?machine_id='.$node->machine_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.read_only', true)
            ->assertJsonPath('data.0.status', 'unknown')->assertJsonCount(2, 'data.0.references');
        $this->assertStringNotContainsString('never-expose-this', $response->getContent());
        $this->assertDatabaseCount('v2_server_certificate', 0);
        $this->assertSame($before, $node->fresh()->getRawOriginal());
    }

    public function test_login_image_is_independent_and_size_is_validated_and_exposed_to_mcp(): void
    {
        $save = $this->path(ConfigController::class.'@save');
        $this->postJson($save, ['logo' => 'https://example.test/global.png', 'admin_login_image' => 'https://example.test/card.webp', 'admin_login_image_width' => 320, 'admin_login_image_height' => 120])->assertOk();
        $this->postJson($save, ['logo' => 'https://example.test/global2.png'])->assertOk();
        $this->getJson($this->path(CommController::class.'@config'))->assertOk()->assertJsonPath('data.admin_login_image', 'https://example.test/card.webp')->assertJsonPath('data.admin_login_image_width', 320);
        $this->getJson($this->path(ConfigController::class.'@fetch'))->assertOk()->assertJsonPath('data.frontend.admin_login_image_height', 120);
        $this->postJson($save, ['admin_login_image_width' => 401])->assertUnprocessable();
        $this->postJson($save, ['admin_login_image' => 'javascript:alert(1)'])->assertUnprocessable();
        $catalog = app(AdminOperationCatalog::class);
        $this->assertNotNull($catalog->resolve('config.upload_login_image.post'));
        $fields = $catalog->resolve('config.save.post')['request_fields'];
        $this->assertContains('admin_login_image_width', array_column($fields, 'name'));
    }

    public function test_draft_upload_never_deletes_the_current_logo_even_when_config_save_fails(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site-branding/logo-old.png', 'existing image');
        admin_setting(['logo' => 'http://localhost/storage/site-branding/logo-old.png']);
        $image = UploadedFile::fake()->createWithContent('new.png', $this->png(256,256));
        $this->post($this->path(ConfigController::class.'@uploadLogo'), ['file' => $image])->assertOk();
        $this->postJson($this->path(ConfigController::class.'@save'), ['admin_login_image_width' => -1])->assertUnprocessable();
        Storage::disk('public')->assertExists('site-branding/logo-old.png');
        $this->assertSame('http://localhost/storage/site-branding/logo-old.png', admin_setting('logo'));
        $this->post($this->path(ConfigController::class.'@uploadLoginImage'), ['file' => UploadedFile::fake()->createWithContent('wide.png', $this->png(320,120))])->assertOk();
    }

    private function legacyNode(): Server
    {
        $machine = ServerMachine::create(['name' => 'legacy host', 'token' => Str::random(32), 'is_active' => true]);
        return Server::withoutEvents(fn () => Server::create([
            'machine_id' => $machine->id, 'name' => 'legacy hy2', 'type' => 'hysteria',
            'host' => 'node.example.test', 'port' => 443, 'server_port' => 24443,
            'rate' => 1, 'enabled' => true, 'protocol_settings' => ['version' => 2, 'tls' => ['server_name' => 'node.example.test']],
            'cert_config' => ['cert_mode' => 'file', 'cert_file' => '/etc/node/fullchain.pem', 'key_file' => '/etc/node/key.pem', 'key_content' => 'never-expose-this'],
            'custom_routes' => [['domain' => ['www.gstatic.com'], 'outbound' => 'direct']],
        ]));
    }
    private function path(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) if ($route->getActionName() === $action) return '/'.$route->uri();
        $this->fail('Missing route '.$action);
    }
    private function png(int $width, int $height): string
    {
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC',$width,$height,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\0\0\0\0",$width),$height))).$chunk('IEND','');
    }
}
