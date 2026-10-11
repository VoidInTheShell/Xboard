<?php

namespace Tests\Feature\Subscription;

use App\Models\Server;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NodeReceiptDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_heartbeats_preserve_receipts_without_rewriting_the_database(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
        Cache::flush();
        $node = Server::create(['name' => 'receipt-test', 'type' => 'vless',
            'host' => 'example.test', 'port' => '443', 'server_port' => '443',
            'group_ids' => [1], 'show' => true, 'rate' => 1]);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'xray_apply')) $writes++;
        });
        $metrics = ['config_apply' => ['desired_revision' => 1, 'applied_revision' => 1, 'status' => 'applied']];
        ServerService::updateMetrics($node, $metrics);
        ServerService::updateMetrics($node, $metrics);
        $this->assertSame(1, $writes);
        $this->assertSame('applied', Cache::get("xray_config_apply:{$node->id}")['status']);
        $metrics['config_apply']['desired_revision'] = 2;
        ServerService::updateMetrics($node, $metrics);
        $this->assertSame(2, $writes);
        $this->assertSame(2, Cache::get("xray_config_apply:{$node->id}")['desired_revision']);
    }
}
