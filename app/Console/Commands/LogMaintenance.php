<?php
namespace App\Console\Commands;

use App\Services\Logs\LogStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class LogMaintenance extends Command
{
    protected $signature='logs:maintain {--force : Run retention regardless of interval}';
    protected $description='Apply panel log retention, storage budgets and daily archives';
    public function handle(LogStorage $storage): int
    {
        $lock = Cache::lock('logs:maintenance:lock', 1800);
        if (!$lock->get()) return self::SUCCESS;
        try {
            $this->line(json_encode($storage->maintain((bool)$this->option('force')), JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
