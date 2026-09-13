<?php

namespace App\Services\Usage;

class UsageSettings
{
    public static function get(string $key): mixed
    {
        $default = config('usage.' . $key);
        return in_array($key, ['enabled', 'history_days', 'access_days', 'identity_days'], true)
            ? admin_setting('usage_' . $key, $default) : $default;
    }
}
