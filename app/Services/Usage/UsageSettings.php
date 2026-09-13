<?php

namespace App\Services\Usage;

class UsageSettings
{
    public static function get(string $key): mixed
    {
        if ($key==='enabled') return \App\Services\Logs\LogSettings::configured()
            ? (bool) \App\Services\Logs\LogSettings::get()['usageEnabled']
            : (bool)admin_setting('usage_enabled',config('usage.enabled'));
        if (in_array($key,['history_days','access_days','identity_days'],true)) return \App\Services\Logs\LogSettings::policy(['history_days'=>'traffic','access_days'=>'web','identity_days'=>'source'][$key])['days'];
        $default = config('usage.' . $key);
        return in_array($key, ['enabled', 'history_days', 'access_days', 'identity_days'], true)
            ? admin_setting('usage_' . $key, $default) : $default;
    }
}
