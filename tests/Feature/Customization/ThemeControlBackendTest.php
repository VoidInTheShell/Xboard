<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V1\Guest\CommController as GuestCommController;
use App\Http\Controllers\V1\User\NoticeController as UserNoticeController;
use App\Http\Controllers\V1\User\ServerController as UserServerController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\NoticeController as AdminNoticeController;
use App\Models\Notice;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Support\Setting;
use App\Services\Logs\LogSettings;
use App\Services\Usage\MachineTrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThemeControlBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        config()->set('usage.enabled', true);
        LogSettings::forget();
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_theme_controls_are_saved_and_exposed_to_both_frontends(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $payload = [
            'user_login_title' => '欢迎加入 UEG Net',
            'user_login_description' => '接入 UEG 全球网络',
            'user_hidden_menus' => ['/usage', '/invite'],
            'admin_hidden_menus' => ['/leaderboard', '/extensions'],
            'user_support_enabled' => false,
            'user_support_description' => '请通过工单联系我们',
            'user_support_telegram_label' => '@ueg_support',
            'user_support_telegram_url' => 'https://t.me/ueg_support',
            'user_support_group_label' => 'UEG 用户群',
            'user_support_group_url' => 'https://t.me/ueg_group',
            'user_support_ticket_enabled' => true,
            'user_support_knowledge_enabled' => false,
        ];

        $this->postJson($this->routePath(ConfigController::class . '@save'), $payload)
            ->assertOk()
            ->assertJsonPath('data', true);

        $this->getJson($this->routePath(ConfigController::class . '@fetch') . '?key=frontend')
            ->assertOk()
            ->assertJsonPath('data.frontend.user_login_title', '欢迎加入 UEG Net')
            ->assertJsonPath('data.frontend.user_hidden_menus.1', '/invite')
            ->assertJsonPath('data.frontend.admin_hidden_menus.1', '/extensions')
            ->assertJsonPath('data.frontend.user_support_enabled', false)
            ->assertJsonPath('data.frontend.user_support_knowledge_enabled', false);

        $this->getJson($this->routePath(GuestCommController::class . '@config'))
            ->assertOk()
            ->assertJsonPath('data.user_login_description', '接入 UEG 全球网络')
            ->assertJsonPath('data.user_hidden_menus.0', '/usage')
            ->assertJsonPath('data.admin_hidden_menus.0', '/leaderboard')
            ->assertJsonPath('data.user_support_telegram_url', 'https://t.me/ueg_support')
            ->assertJsonPath('data.notice_acknowledgement', true);

        $this->postJson($this->routePath(ConfigController::class . '@save'), [
            'user_hidden_menus' => ['/servers'],
        ])->assertUnprocessable();
        $this->postJson($this->routePath(ConfigController::class . '@save'), [
            'user_support_group_url' => 'javascript:alert(1)',
        ])->assertUnprocessable();
    }

    public function test_logo_upload_accepts_the_cropped_png_and_returns_a_public_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user(['is_admin' => true]));

        $uploaded = $this->post(
            $this->routePath(ConfigController::class . '@uploadLogo'),
            ['file' => UploadedFile::fake()->createWithContent('logo.png', $this->png(256, 256))]
        )->assertOk()->json('data.url');

        $this->assertStringStartsWith('http://localhost/storage/site-branding/logo-', $uploaded);
        $path = 'site-branding/' . basename(parse_url($uploaded, PHP_URL_PATH));
        Storage::disk('public')->assertExists($path);

        $this->postJson($this->routePath(ConfigController::class . '@save'), ['logo' => $uploaded])
            ->assertOk();
        $this->assertSame($uploaded, admin_setting('logo'));

        $this->post(
            $this->routePath(ConfigController::class . '@uploadLogo'),
            ['file' => UploadedFile::fake()->createWithContent('wrong.png', $this->png(128, 128))]
        )->assertUnprocessable();
    }

    public function test_notice_acknowledgement_is_per_user_and_invalidated_by_content_revision(): void
    {
        $admin = $this->user(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->postJson($this->routePath(AdminNoticeController::class . '@save'), [
            'title' => '必须确认的公告',
            'content' => '<p>第一版内容</p>',
            'tags' => [],
            'show' => true,
            'popup' => true,
            'pinned' => true,
            'require_ack' => true,
        ])->assertOk();
        $notice = Notice::where('title', '必须确认的公告')->firstOrFail();
        Notice::create([
            'title' => '普通公告',
            'content' => '普通内容',
            'show' => true,
            'popup' => false,
            'pinned' => false,
            'require_ack' => false,
        ]);

        $user = $this->user();
        Sanctum::actingAs($user);
        $this->getJson($this->routePath(UserNoticeController::class . '@fetch'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $notice->id)
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.0.acknowledged', false);

        $this->postJson($this->routePath(UserNoticeController::class . '@acknowledge'), [
            'id' => $notice->id,
            'revision' => 99,
        ])->assertStatus(409);
        $this->postJson($this->routePath(UserNoticeController::class . '@acknowledge'), [
            'id' => $notice->id,
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data', true);
        $this->getJson($this->routePath(UserNoticeController::class . '@fetch'))
            ->assertJsonPath('data.0.acknowledged', true);

        $other = $this->user();
        Sanctum::actingAs($other);
        $this->getJson($this->routePath(UserNoticeController::class . '@fetch'))
            ->assertJsonPath('data.0.acknowledged', false);

        Sanctum::actingAs($admin);
        $this->postJson($this->routePath(AdminNoticeController::class . '@save'), [
            'id' => $notice->id,
            'title' => '必须确认的公告',
            'content' => '<p>第二版内容</p>',
            'tags' => [],
            'show' => true,
            'popup' => true,
            'pinned' => true,
            'require_ack' => true,
        ])->assertOk();
        $this->assertSame(2, (int) $notice->fresh()->revision);

        Sanctum::actingAs($user);
        $this->getJson($this->routePath(UserNoticeController::class . '@fetch'))
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.acknowledged', false);
    }

    public function test_self_use_mode_adds_machine_remaining_traffic_and_changes_the_etag(): void
    {
        $machine = ServerMachine::create([
            'name' => 'quota-machine',
            'token' => Str::random(32),
            'is_active' => true,
            'traffic_policy' => [
                'limit' => '10',
                'unit' => 'GiB',
                'resetDay' => '1',
                'zone' => 'Asia/Shanghai',
                'direction' => 'both',
                'warning' => '80',
            ],
        ]);
        Server::create([
            'name' => 'quota-node',
            'type' => 'vless',
            'host' => 'example.com',
            'port' => 443,
            'server_port' => 443,
            'group_ids' => [1],
            'rate' => 1,
            'enabled' => true,
            'show' => true,
            'machine_id' => $machine->id,
        ]);
        $user = $this->user([
            'group_id' => 1,
            'expired_at' => time() + 86400,
            'transfer_enable' => 100 * 1073741824,
            'u' => 0,
            'd' => 0,
        ]);
        Sanctum::actingAs($user);
        $path = $this->routePath(UserServerController::class . '@fetch');

        $this->getJson($path)->assertOk()->assertJsonMissingPath('data.0.machine_traffic');

        admin_setting(['self_use_mode' => true]);
        DB::table('v2_usage_traffic')->insert([
            'layer' => 'nic',
            'machine_id' => $machine->id,
            'node_id' => 0,
            'user_id' => 0,
            'resource' => 'host:eth0',
            'bucket' => intdiv(time(), 3600) * 3600,
            'up' => 1073741824,
            'down' => 2147483648,
        ]);
        $this->assertSame(
            7 * 1073741824,
            app(MachineTrafficService::class)->summary($machine->fresh())['remaining_bytes']
        );
        $response = $this->getJson($path)
            ->assertOk();
        $response
            ->assertJsonPath('data.0.machine_traffic.remaining_bytes', 7 * 1073741824)
            ->assertJsonPath('data.0.machine_traffic.unlimited', false);
        $firstEtag = $response->headers->get('ETag');

        DB::table('v2_usage_traffic')->where('machine_id', $machine->id)->update([
            'up' => 2147483648,
        ]);
        $this->withHeader('If-None-Match', $firstEtag)->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.0.machine_traffic.remaining_bytes', 6 * 1073741824);
    }

    private function user(array $attributes = []): User
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

    private function png(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $header = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
        $scanline = "\0" . str_repeat("\0\0\0\0", $width);

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $header)
            . $chunk('IDAT', gzcompress(str_repeat($scanline, $height)))
            . $chunk('IEND', '');
    }
}
