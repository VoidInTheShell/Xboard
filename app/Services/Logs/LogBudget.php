<?php

namespace App\Services\Logs;

use Illuminate\Support\Facades\Cache;

class LogBudget
{
    // This is a logical retention budget (sampled DB rows + actual file bytes),
    // not a guarantee about database pages, indexes or host disk space.
    public static function accepts(int $bytes = 0): bool
    {
        try {
            return Cache::lock('logs:admission',5)->block(1,function () use ($bytes) {
                $state=Cache::get('logs:storage-state');
                if (!$state) return true;
                $reserved=(int)Cache::get('logs:reserved-bytes',0);
                if ($state['bytes']+$reserved+$bytes >= LogSettings::get()['totalGiB']*1073741824) return false;
                if ($bytes>0) Cache::put('logs:reserved-bytes',$reserved+$bytes,86400);
                return true;
            });
        } catch (\Throwable) { return true; }
    }
}
