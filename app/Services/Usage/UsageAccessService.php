<?php

namespace App\Services\Usage;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageAccessService
{
    public function record(Request $request, int $userId, string $kind, string $action, string $result, string $path = ''): void
    {
        if (!\App\Services\Usage\UsageSettings::get('enabled')) return;
        if (!\App\Services\Logs\LogSettings::enabled($kind === 'subscription' ? 'subscription' : 'web') || !\App\Services\Logs\LogBudget::accepts(2048)) return;
        $ua = mb_substr((string) $request->userAgent(), 0, 512);
        // Do not persist URL queries, Authorization, subscription tokens or body.
        DB::table('v2_usage_event')->insert([
            'user_id' => $userId, 'kind' => $kind, 'action' => $action, 'result' => $result,
            'ip' => filter_var($request->ip(), FILTER_VALIDATE_IP) ? $request->ip() : '',
            'platform' => self::platform($ua, $kind === 'panel' ? $request->header('Sec-CH-UA-Platform', '') : ''),
            'user_agent' => $ua, 'path' => mb_substr(explode('?', $path)[0], 0, 128),
            'recorded_at' => time(),
        ]);
    }

    public static function platform(string $ua, string $hint = ''): string
    {
        $hint = trim($hint, '" ');
        if (in_array($hint, ['Windows', 'macOS', 'Linux', 'Android', 'iOS'], true)) return $hint;
        foreach (['/Android/i' => 'Android', '/iPhone|iPad|iPod/i' => 'iOS', '/Windows/i' => 'Windows',
            '/Macintosh|Mac OS X/i' => 'macOS', '/Linux/i' => 'Linux'] as $pattern => $name) {
            if (preg_match($pattern, $ua)) return $name;
        }
        return 'unknown';
    }

    public static function client(string $ua): string
    {
        foreach (['sing-box', 'Clash', 'Shadowrocket', 'Stash', 'Surge', 'Hiddify', 'v2rayN', 'v2rayNG', 'NekoBox',
            'Edge', 'Firefox', 'Chrome', 'Safari'] as $client) {
            if (stripos($ua, $client) !== false || ($client === 'Edge' && stripos($ua, 'Edg/') !== false)) return $client;
        }
        return '未识别';
    }
}
