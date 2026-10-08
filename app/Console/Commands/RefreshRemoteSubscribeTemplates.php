<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\SubscribeTemplate;
use App\Services\ChangeEventService;
use App\Services\RemoteSubscribeTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshRemoteSubscribeTemplates extends Command
{
    protected $signature = 'subscribe-template:refresh';
    protected $description = 'Refresh remote subscription templates whose update interval has elapsed';

    public function handle(RemoteSubscribeTemplateService $templates, ChangeEventService $changes): int
    {
        $failed = false;
        foreach (SubscribeTemplate::where('auto_update', true)->where('next_update_at', '<=', now())->get() as $row) {
            try {
                $prepared = $templates->prepare($row->name, (string) $row->revision, null, true);
                DB::transaction(function () use ($templates, $changes, $prepared) {
                    $templates->applyPrepared($prepared);
                    $changes->commitSystemMutation('system', 'subscribe_template.remote', 'subscribe_template.remote.automatic', $prepared['name']);
                });
                $this->info($row->name . ': checked');
            } catch (ApiException $e) {
                if ($e->getCode() !== 409) $failed = true;
                $this->warn($row->name . ': ' . $e->getMessage());
            } catch (\Throwable) {
                $failed = true;
                $this->error($row->name . ': update failed; check database and cache availability');
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
