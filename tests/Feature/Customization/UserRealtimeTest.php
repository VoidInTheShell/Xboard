<?php

namespace Tests\Feature\Customization;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\TrafficResetService;
use App\Services\NodeRegistry;
use App\Services\UserRealtimeService;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class UserRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        Queue::fake();
    }

    public function test_ticket_is_single_use_and_expires(): void
    {
        $ticket = UserRealtimeService::issueTicket(42);
        $this->assertSame('/ws', $ticket['websocket_path']);
        $this->assertSame(42, UserRealtimeService::consumeTicket($ticket['ticket']));
        $this->assertNull(UserRealtimeService::consumeTicket($ticket['ticket']));
        $this->assertNull(UserRealtimeService::consumeTicket('invalid'));
        $ticket = UserRealtimeService::issueTicket(43);
        $this->travel(31)->seconds();
        $this->assertNull(UserRealtimeService::consumeTicket($ticket['ticket']));
    }

    public function test_quota_update_publishes_only_an_invalidation_for_that_user(): void
    {
        Redis::shouldReceive('publish')->once()->with('node:push', Mockery::on(function ($json) {
            $this->assertSame(['user_id' => 7, 'event' => 'account.updated', 'data' => []], json_decode($json, true));
            return true;
        }));
        $user = new User();
        $user->forceFill(['id' => 7, 'transfer_enable' => 100])->syncOriginal();
        $user->transfer_enable = 500;
        $user->syncChanges();
        (new UserObserver(Mockery::mock(TrafficResetService::class)))->updated($user);
    }

    public function test_ticket_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\InitializePlugins::class);
        $this->postJson('/api/v1/user/events/ticket')->assertForbidden();
    }

    public function test_ticket_endpoint_binds_to_the_current_user(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\InitializePlugins::class);
        $user = new User();
        $user->forceFill(['id' => 42]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/user/events/ticket', ['user_id' => 99])->assertOk();
        $this->assertSame(42, UserRealtimeService::consumeTicket($response->json('data.ticket')));
    }

    public function test_user_socket_is_scoped_and_cannot_send_node_commands(): void
    {
        $worker = new NodeWorker('127.0.0.1', 0);
        $first = Mockery::mock(TcpConnection::class);
        $second = Mockery::mock(TcpConnection::class);
        $first->shouldReceive('send')->once()->with(Mockery::on(fn ($s) => json_decode($s, true)['event'] === 'auth.success'));
        $second->shouldReceive('send')->once()->with(Mockery::on(fn ($s) => json_decode($s, true)['event'] === 'auth.success'));
        foreach ([[7, $first], [8, $second]] as [$id, $conn]) {
            $ticket = UserRealtimeService::issueTicket($id)['ticket'];
            $worker->onWebSocketConnect($conn, new Request("GET /ws?user_ticket={$ticket} HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        }
        $first->shouldReceive('send')->once()->with(Mockery::on(fn ($s) => json_decode($s, true)['event'] === 'account.updated'));
        $worker->pushAccountUpdated(7);
        $worker->onMessage($first, json_encode(['event' => 'node.status', 'data' => ['node_id' => 1]]));
        $worker->onClose($first);
        $worker->pushAccountUpdated(7);
        $worker->onClose($second);
    }

    public function test_delayed_old_socket_close_preserves_replacement_heartbeat(): void
    {
        $worker = new NodeWorker('127.0.0.1', 0);
        $old = Mockery::mock(TcpConnection::class);
        $new = Mockery::mock(TcpConnection::class);
        $old->nodeId = 77;
        $old->shouldReceive('close')->once();
        NodeRegistry::add(77, $old);
        NodeRegistry::add(77, $new);
        Cache::put('node_ws_alive:77', true, 86400);
        $worker->onClose($old);
        $this->assertSame($new, NodeRegistry::get(77));
        $this->assertTrue(Cache::get('node_ws_alive:77'));
        NodeRegistry::remove(77, $new);
    }
}
