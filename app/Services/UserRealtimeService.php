<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class UserRealtimeService
{
    public static function issueTicket(int $userId): array
    {
        $ticket = bin2hex(random_bytes(32));
        Cache::put(self::ticketKey($ticket), $userId, 30);
        return ['ticket' => $ticket, 'websocket_path' => '/ws'];
    }

    public static function consumeTicket(string $ticket): ?int
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $ticket)) return null;
        $key = self::ticketKey($ticket);
        // pull is read-then-delete; serialize concurrent ticket consumers.
        $id = Cache::lock($key . ':consume', 3)->get(fn () => Cache::pull($key));
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    public static function notifyAccountChanged(int $userId): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'user_id' => $userId, 'event' => 'account.updated', 'data' => [],
            ]));
        } catch (\Throwable) {
            Log::warning('Account notification unavailable', ['user_id' => $userId]);
        }
    }

    private static function ticketKey(string $ticket): string
    {
        return 'user_ws_ticket:' . hash('sha256', $ticket);
    }
}
