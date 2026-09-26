<?php

namespace Tests\Feature\Customization;

use App\Models\Setting;
use App\Models\StatUser;
use App\Models\User;
use App\Services\Logs\LogSettings;
use App\Support\Setting as SettingSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTrafficLogStatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        config()->set('usage.enabled', true);
        LogSettings::forget();
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(SettingSupport::class);
    }

    private function setLegacyStats(bool $enabled): void
    {
        $settings = LogSettings::get();
        foreach ($settings['policies'] as &$policy) {
            if ($policy['id'] === 'legacy') {
                $policy['enabled'] = $enabled;
            }
        }
        unset($policy);
        Setting::createOrUpdate('log_policy', $settings);
        LogSettings::forget();
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

    public function test_traffic_log_reports_disabled_when_legacy_stats_are_off(): void
    {
        $user = $this->makeUser();
        StatUser::create([
            'user_id' => $user->id,
            'server_rate' => 1,
            'u' => 1024,
            'd' => 2048,
            'record_type' => 'd',
            'record_at' => now()->startOfMonth()->addDay()->timestamp,
        ]);
        $this->setLegacyStats(false);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/stat/getTrafficLog')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.logs', []);
    }

    public function test_traffic_log_returns_month_records_when_enabled(): void
    {
        $user = $this->makeUser();
        $otherUser = $this->makeUser();
        StatUser::create([
            'user_id' => $user->id,
            'server_rate' => 1,
            'u' => 1024,
            'd' => 2048,
            'record_type' => 'd',
            'record_at' => now()->startOfMonth()->addDay()->timestamp,
        ]);
        // Records from other users and older months stay invisible.
        StatUser::create([
            'user_id' => $otherUser->id,
            'server_rate' => 1,
            'u' => 4096,
            'd' => 8192,
            'record_type' => 'd',
            'record_at' => now()->startOfMonth()->addDay()->timestamp,
        ]);
        StatUser::create([
            'user_id' => $user->id,
            'server_rate' => 1,
            'u' => 16384,
            'd' => 32768,
            'record_type' => 'd',
            'record_at' => now()->subMonth()->startOfMonth()->timestamp,
        ]);
        $this->setLegacyStats(true);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/stat/getTrafficLog')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.u', 1024)
            ->assertJsonPath('data.logs.0.d', 2048);
    }
}
