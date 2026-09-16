<?php
namespace App\Services\Logs;

class LogHorizonRepository extends \Laravel\Horizon\Repositories\RedisJobRepository
{
    private function refreshRetention(): void
    {
        $s=LogSettings::get();
        $this->recentJobExpires=$this->pendingJobExpires=$this->completedJobExpires=$s['horizonMinutes'];
        $this->recentFailedJobExpires=$this->failedJobExpires=$this->monitoredJobExpires=$s['horizonFailedDays']*1440;
    }
    protected function connection() { $this->refreshRetention(); return parent::connection(); }
    protected function minutesForType($type) { $this->refreshRetention(); return parent::minutesForType($type); }
}
