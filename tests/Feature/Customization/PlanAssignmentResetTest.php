<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AdminOperationCatalog;
use App\Services\ServerService;
use App\Services\TrafficResetService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PlanAssignmentResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
        Sanctum::actingAs($this->user(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_assignment_inherits_limits_and_makes_the_same_subscription_usable(): void
    {
        $group = ServerGroup::unguarded(fn () => ServerGroup::create(['name' => 'assigned']));
        $plan = $this->plan(['group_id' => $group->id]);
        $inviter = $this->user();
        $user = $this->user(['transfer_enable' => 0, 'u' => 100, 'd' => 200, 'invite_user_id' => $inviter->id]);
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'subscription test', 'type' => 'shadowsocks', 'group_ids' => [$group->id],
            'host' => 'example.test', 'port' => '12345', 'server_port' => 12345,
            'show' => true, 'enabled' => true, 'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ]));
        $this->postJson($this->path(UserController::class.'@update'), ['id' => $user->id, 'plan_id' => $plan->id])->assertOk();
        $user->refresh();
        $this->assertSame(100 * 1073741824, $user->transfer_enable);
        $this->assertSame(50, $user->speed_limit);
        $this->assertSame(3, $user->device_limit);
        $this->assertSame($group->id, $user->group_id);
        $this->assertSame($inviter->id, $user->invite_user_id);
        $this->assertSame(100, $user->u);
        $this->assertSame(200, $user->d);
        $this->assertNull($user->expired_at);
        $this->assertTrue($user->isAvailable());
        $this->assertContains($user->id, ServerService::getAvailableUsers($node)->pluck('id')->all());
        foreach (['ClashMeta/1.19.27; mihomo/1.19.27', 'ClashMeta', 'FlClash/0.8.92', 'clash-verge/v1.6.6'] as $ua) {
            $response = $this->get('/s/'.$user->token.'?flag=meta', ['User-Agent' => $ua])->assertOk();
            $this->assertCount(1, Yaml::parse($response->getContent())['proxies']);
            $this->assertStringContainsString('total=107374182400', $response->headers->get('subscription-userinfo'));
        }
    }

    public function test_explicit_overrides_and_unrelated_updates_are_preserved(): void
    {
        $plan = $this->plan();
        $user = $this->user(['transfer_enable' => 1024, 'speed_limit' => 5, 'device_limit' => 2]);
        $this->postJson($this->path(UserController::class.'@update'), [
            'id' => $user->id, 'plan_id' => $plan->id, 'transfer_enable' => 0, 'speed_limit' => null, 'device_limit' => 9,
        ])->assertOk();
        $user->refresh();
        $this->assertSame(0, $user->transfer_enable);
        $this->assertNull($user->speed_limit);
        $this->assertSame(9, $user->device_limit);
        $this->assertFalse($user->isAvailable());
        $this->postJson($this->path(UserController::class.'@update'), ['id' => $user->id, 'remarks' => 'preserve limits'])->assertOk();
        $this->assertSame(0, $user->fresh()->transfer_enable);
    }

    public function test_assigning_the_same_plan_repairs_missing_limits_without_unbanning_or_extending(): void
    {
        $plan = $this->plan();
        $expiry = time() - 86400;
        $user = $this->user(['plan_id' => $plan->id, 'transfer_enable' => 0, 'banned' => true, 'expired_at' => $expiry]);
        $this->postJson($this->path(UserController::class.'@update'), ['id' => $user->id, 'plan_id' => $plan->id])->assertOk();
        $user->refresh();
        $this->assertSame(100 * 1073741824, $user->transfer_enable);
        $this->assertTrue($user->banned);
        $this->assertSame($expiry, $user->expired_at);
        $this->assertFalse($user->isAvailable());
    }

    public function test_custom_day_api_roundtrip_validation_and_mcp_discovery(): void
    {
        $save = $this->path(PlanController::class.'@save');
        $payload = ['name' => 'custom reset', 'transfer_enable' => 100, 'reset_traffic_method' => 5, 'reset_traffic_day' => 31];
        $this->postJson($save, $payload)->assertOk();
        $plan = Plan::where('name', 'custom reset')->firstOrFail();
        $this->assertSame(31, $plan->reset_traffic_day);
        $this->getJson($this->path(PlanController::class.'@fetch'))->assertOk()->assertJsonPath('data.0.reset_traffic_day', 31);
        foreach ([0, 32, 1.5, null] as $day) {
            $this->postJson($save, array_merge($payload, ['id' => $plan->id, 'reset_traffic_day' => $day]))->assertUnprocessable();
        }
        $this->postJson($save, ['name' => 'missing day', 'transfer_enable' => 100, 'reset_traffic_method' => 5])->assertUnprocessable();
        $fields = app(AdminOperationCatalog::class)->resolve('plan.save.post')['request_fields'];
        $this->assertContains('reset_traffic_day', array_column($fields, 'name'));
    }

    public function test_custom_dates_clamp_month_end_and_advance_after_midnight(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $user = $this->user();
        $user->setRelation('plan', $this->plan(['reset_traffic_method' => 5, 'reset_traffic_day' => 31]));
        $service = app(TrafficResetService::class);
        foreach ([
            ['2026-02-10 12:00:00', '2026-02-28 00:00:00'],
            ['2028-02-10 12:00:00', '2028-02-29 00:00:00'],
            ['2026-02-28 00:00:00', '2026-03-31 00:00:00'],
            ['2026-04-01 00:00:00', '2026-04-30 00:00:00'],
            ['2026-12-31 00:00:00', '2027-01-31 00:00:00'],
        ] as [$now, $expected]) {
            Carbon::setTestNow(Carbon::parse($now, 'Asia/Shanghai'));
            $next = $service->calculateNextResetTime($user);
            $this->assertSame($expected, $next->format('Y-m-d H:i:s'));
            $this->assertSame('Asia/Shanghai', $next->timezoneName);
        }
        $user->plan->reset_traffic_day = 15;
        Carbon::setTestNow(Carbon::parse('2026-10-14 23:59:59', 'Asia/Shanghai'));
        $this->assertSame('2026-10-15 00:00:00', $service->calculateNextResetTime($user)->format('Y-m-d H:i:s'));
    }

    public function test_editing_only_the_custom_day_reschedules_existing_users_without_resetting_usage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', config('app.timezone')));
        $plan = $this->plan(['reset_traffic_method' => 5, 'reset_traffic_day' => 10]);
        $user = $this->user(['plan_id' => $plan->id, 'u' => 100, 'd' => 200, 'next_reset_at' => 1234]);
        $this->postJson($this->path(PlanController::class.'@save'), [
            'id' => $plan->id, 'name' => $plan->name, 'transfer_enable' => 100, 'reset_traffic_method' => 5, 'reset_traffic_day' => 20,
        ])->assertOk();
        $user->refresh();
        $this->assertSame(Carbon::parse('2026-10-20 00:00:00', config('app.timezone'))->timestamp, $user->next_reset_at);
        $this->assertSame(100, $user->u);
        $this->assertSame(200, $user->d);
        $this->assertTrue(app(TrafficResetService::class)->performReset($user));
        $this->assertSame(0, $user->fresh()->u);
        $this->assertSame(0, $user->fresh()->d);
    }

    public function test_existing_reset_modes_keep_their_behavior(): void
    {
        $user = $this->user();
        $user->setRelation('plan', $this->plan(['reset_traffic_method' => 2]));
        $this->assertNull(app(TrafficResetService::class)->calculateNextResetTime($user));
        $user->plan->reset_traffic_method = 0;
        $this->assertNull(app(TrafficResetService::class)->calculateNextResetTime($user));
        $user->expired_at = time() + 86400 * 90;
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', config('app.timezone')));
        $this->assertSame('2026-11-01', app(TrafficResetService::class)->calculateNextResetTime($user)->format('Y-m-d'));
    }

    private function user(array $extra = []): User
    {
        return User::create(array_merge(['email' => Str::uuid().'@example.test', 'password' => 'test', 'uuid' => Str::uuid(), 'token' => Str::random(32), 'u' => 0, 'd' => 0, 'banned' => false, 'expired_at' => null], $extra));
    }

    private function plan(array $extra = []): Plan
    {
        return Plan::create(array_merge(['name' => 'plan', 'transfer_enable' => 100, 'speed_limit' => 50, 'device_limit' => 3, 'reset_traffic_method' => 2], $extra));
    }

    private function path(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) if ($route->getActionName() === $action) return '/'.$route->uri();
        $this->fail('Missing route '.$action);
    }
}
