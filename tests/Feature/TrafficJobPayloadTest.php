<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TrafficJobPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true]);
        config()->set('cache.default', 'array');
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
    }

    public function test_large_server_configuration_is_excluded_from_real_queue_payloads(): void
    {
        $server = $this->server();
        $traffic = [42 => [100, 200]];
        $jobs = [
            [new TrafficFetchJob($server, $traffic, 'vless', 1700000000), ['rate' => '1.25']],
            [new StatServerJob($server, $traffic, 'vless'), ['id' => 902]],
            [new StatUserJob($server, $traffic, 'vless'), ['rate' => '1.25']],
        ];

        foreach ($jobs as [$job, $expectedServer]) {
            // Horizon stores the same payload produced by the Redis queue connection.
            $queue = app('queue')->connection('redis');
            $payload = (new \ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, $job->queue);
            $this->assertLessThan(8192, strlen($payload), get_class($job));
            $this->assertStringNotContainsString('private-certificate-sentinel', $payload);
            $this->assertStringNotContainsString('custom_routes', $payload);
            $restored = unserialize(json_decode($payload, true)['data']['command']);
            $this->assertSame($expectedServer, (new \ReflectionProperty($restored, 'server'))->getValue($restored));
            $this->assertSame($traffic, (new \ReflectionProperty($restored, 'data'))->getValue($restored));
            $this->assertSame($job->queue, $restored->queue);
        }
    }

    public function test_compact_jobs_preserve_rate_snapshot_traffic_and_daily_monthly_totals(): void
    {
        $this->executeRoundTrip(false);
    }

    public function test_pre_upgrade_jobs_with_full_server_payloads_remain_executable(): void
    {
        $this->executeRoundTrip(true);
    }

    private function executeRoundTrip(bool $legacy): void
    {
        $user = User::create(['email' => 'traffic-payload@example.test', 'password' => 'unused-test-password',
            'uuid' => '8ade85b0-fbcb-4b5c-a4db-73b1ce23b8ea', 'token' => 'traffic-payload-test-token', 'u' => 10, 'd' => 20]);
        $server = $this->server();
        DB::table('v2_server')->insert(['id' => 902, 'name' => 'Traffic test', 'type' => 'vless',
            'host' => 'traffic.example.test', 'port' => 443, 'server_port' => 443, 'rate' => 9, 'u' => 2, 'd' => 3]);
        $traffic = [$user->id => [100, 200]];
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $user->id)->andReturn(1);
        $jobs = [new TrafficFetchJob($server, $traffic, 'vless', 1700000000)];
        foreach (['d', 'm'] as $bucket) {
            $jobs[] = new StatServerJob($server, $traffic, 'vless', $bucket);
            $jobs[] = new StatUserJob($server, $traffic, 'vless', $bucket);
        }
        foreach ($jobs as $job) {
            if ($legacy) {
                // Model bytes already queued by the previous release, whose constructor kept all fields.
                (new \ReflectionProperty($job, 'server'))->setValue($job, $server);
            }
            unserialize(serialize($job))->handle();
        }
        $this->assertSame(135, (int) $user->fresh()->u);
        $this->assertSame(270, (int) $user->fresh()->d);
        $this->assertSame(202, (int) DB::table('v2_server')->where('id', 902)->value('u'));
        $this->assertSame(403, (int) DB::table('v2_server')->where('id', 902)->value('d'));
        $this->assertSame(2, DB::table('v2_stat_server')->count());
        $this->assertSame(2, DB::table('v2_stat_user')->count());
        foreach (['d', 'm'] as $bucket) {
            $this->assertDatabaseHas('v2_stat_server', ['server_id' => 902, 'server_type' => 'vless',
                'record_type' => $bucket, 'u' => 100, 'd' => 200]);
            $this->assertDatabaseHas('v2_stat_user', ['user_id' => $user->id, 'server_rate' => '1.25',
                'record_type' => $bucket, 'u' => 125, 'd' => 250]);
        }
    }

    private function server(): array
    {
        return ['id' => 902, 'rate' => '1.25', 'custom_routes' => str_repeat('large-route-entry;', 200000),
            'cert_config' => ['private_key' => 'private-certificate-sentinel'],
            'protocol_settings' => ['irrelevant' => str_repeat('x', 10000)]];
    }
}
