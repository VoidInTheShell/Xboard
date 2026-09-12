<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SubscribeTemplate extends Model
{
    private const DEFAULT_FILES = [
        'singbox' => 'resources/rules/default.sing-box.json',
        // Clash and Clash Meta intentionally share the project-maintained
        // fake-IP whitelist baseline derived from clash-rule-temp.
        'clash' => 'resources/rules/default.clashmeta.yaml',
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
    ];

    private static string $cachePrefix = 'subscribe_template:';

    public static function getContent(string $name): ?string
    {
        $cacheKey = self::$cachePrefix . $name;

        return Cache::store('redis')->remember($cacheKey, 3600, function () use ($name) {
            $content = self::where('name', $name)->value('content');
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
            return self::defaultContent($name);
        });
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
        self::updateOrCreate(
            ['name' => $name],
            ['content' => $content]
        );
        Cache::store('redis')->forget(self::$cachePrefix . $name);
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
