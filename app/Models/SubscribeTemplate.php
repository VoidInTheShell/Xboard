<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscribeTemplate extends Model
{
    private const DEFAULT_FILES = [
        'singbox' => 'resources/rules/default.sing-box.json',
        // Keep separate Clash and Clash Meta files; only their panel node
        // injection/proxy-group sections differ.
        'clash' => 'resources/rules/default.clash.yaml',
        'clashmeta' => 'resources/rules/default.clashmeta.yaml',
        'stash' => 'resources/rules/default.clash.yaml',
        'surge' => 'resources/rules/default.surge.conf',
        'surfboard' => 'resources/rules/default.surfboard.conf',
    ];

    protected $table = 'v2_subscribe_templates';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'content' => 'string',
        'remote_url' => 'encrypted',
        'auto_update' => 'boolean',
        'interval_hours' => 'integer',
        'revision' => 'integer',
        'last_checked_at' => 'datetime',
        'last_updated_at' => 'datetime',
        'next_update_at' => 'datetime',
    ];

    private static string $cachePrefix = 'subscribe_template:';

    public static function getContent(string $name): ?string
    {
        $cacheKey = self::$cachePrefix . $name;

        $read = function () use ($name) {
            $content = self::where('name', $name)->value('content');
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
            return self::defaultContent($name);
        };
        // Never publish uncommitted content into the shared cache, and allow
        // a transaction to read its own template writes.
        if (DB::transactionLevel() > 0) return $read();
        return Cache::store('redis')->remember($cacheKey, 3600, $read);
    }

    public static function defaultContent(string $name): ?string
    {
        $relative = self::DEFAULT_FILES[$name] ?? null;
        if (!$relative) return null;
        $path = base_path($relative);
        return is_file($path) ? file_get_contents($path) ?: null : null;
    }

    public static function setContent(string $name, ?string $content): void
    {
        app(\App\Services\RemoteSubscribeTemplateService::class)->saveManual($name, $content);
    }

    public static function getAllContents(): array
    {
        return self::pluck('content', 'name')->toArray();
    }

    public static function flushCache(string $name): void
    {
        Cache::store('redis')->forget(self::$cachePrefix . $name);
    }
}
