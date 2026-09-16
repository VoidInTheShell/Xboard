<?php

namespace App\Services\Logs;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LogSettings
{
    // Read once per short window, including in long-lived Horizon/Octane workers.
    private static array $cached = [];
    private static float $readAt = 0;
    private static bool $reading = false;
    private static bool $configured = false;

    public static function defaults(): array
    {
        $rows = [
            'audit' => [90,512,2000,1024], 'mail' => [30,256,3000,768],
            'reset' => [90,128,500,512], 'events' => [90,128,500,512],
            'web' => [90,512,10000,512], 'subscription' => [90,512,5000,512],
            'source' => [400,512,300,384], 'review' => [400,64,20,256],
            'traffic' => [400,1024,20000,128], 'ip' => [400,1024,30000,160],
            'nic' => [400,512,5000,128], 'online' => [400,512,10000,128],
            'legacy' => [60,256,5000,128], 'app' => [14,512,20000,512],
            'backup' => [30,64,100,512], 'deprecation' => [14,64,1000,512],
            'queue' => [7,128,100,2048], 'load' => [1,128,30000,128],
        ];
        $policies = [];
        foreach ($rows as $id => [$days,$size,$daily,$bytes]) {
            $policies[] = ['id'=>$id, 'enabled'=>true, 'days'=>$days, 'maxMiB'=>$size,
                'mode'=>'either', 'daily'=>$daily, 'bytes'=>$bytes];
        }
        return ['policies'=>$policies, 'totalGiB'=>10, 'cleanup'=>true, 'interval'=>'hourly',
            'watermark'=>80, 'usageEnabled'=>true, 'auditFailures'=>true, 'auditLogin'=>true,
            'auditReads'=>false, 'level'=>'warning', 'debugMinutes'=>'30', 'rotationMiB'=>20,
            'rotationFiles'=>5, 'compress'=>true, 'archiveAfter'=>90, 'archiveKeep'=>'forever',
            'archiveYears'=>5, 'summaryDaily'=>true, 'summaryUsers'=>1000, 'estimateYears'=>5,
            'compressionRatio'=>35, 'horizonMinutes'=>60, 'horizonFailedDays'=>7, 'appliedHours'=>24,
            'debugUntil'=>null, 'restoreLevel'=>'warning'];
    }

    public static function get(bool $fresh = false): array
    {
        if (self::$reading) return self::$cached ?: self::defaults();
        if (!$fresh && self::$cached && microtime(true) - self::$readAt < 5) return self::$cached;
        $defaults = self::defaults();
        // Installation can run before the settings table exists. Never log here:
        // this method is also used by the log handler itself.
        try {
            self::$reading=true;
            $saved = Setting::where('name', 'log_policy')->value('value');
            if (is_string($saved)) $saved = json_decode($saved, true);
            self::$configured=is_array($saved);
            if (!is_array($saved)) {
                $defaults['usageEnabled']=(bool)(Setting::where('name','usage_enabled')->value('value') ?? config('usage.enabled',false));
                foreach (['access_days'=>['web','subscription'],'history_days'=>['traffic','nic','ip','online'],'identity_days'=>['source']] as $name=>$ids) {
                    $days=(int)(Setting::where('name','usage_'.$name)->value('value') ?? config('usage.'.$name));
                    if ($days>0) foreach ($defaults['policies'] as &$policy) if (in_array($policy['id'],$ids,true)) $policy['days']=$days;
                    unset($policy);
                }
            }
            $defaults = array_replace($defaults, is_array($saved) ? $saved : []);
        } catch (\Throwable) {} finally { self::$reading=false; }
        self::$readAt = microtime(true);
        return self::$cached = $defaults;
    }

    public static function policy(string $id): array
    {
        foreach (self::get()['policies'] as $policy) if ($policy['id'] === $id) return $policy;
        throw new \InvalidArgumentException('Unknown log category');
    }

    public static function enabled(string $id): bool
    {
        return (bool) self::policy($id)['enabled'];
    }

    public static function configured(): bool { self::get(); return self::$configured; }

    public static function level(): string
    {
        $settings = self::get();
        return $settings['level'] === 'debug' && ($settings['debugUntil'] ?? 0) <= time()
            ? $settings['restoreLevel'] : $settings['level'];
    }

    public static function save(array $input): array
    {
        $rules = ['policies'=>'required|array|size:18', 'policies.*.id'=>['required','distinct',Rule::in(array_column(self::defaults()['policies'],'id'))],
            'policies.*.enabled'=>'required|boolean', 'policies.*.days'=>'required|integer|between:1,36500',
            'policies.*.maxMiB'=>'required|numeric|between:1,1048576',
            'policies.*.mode'=>'required|in:days,size,either',
            'policies.*.daily'=>'required|integer|between:0,1000000000', 'policies.*.bytes'=>'required|integer|between:1,1048576',
            'totalGiB'=>'required|numeric|between:0.01,1048576', 'watermark'=>'required|integer|between:1,99',
            'interval'=>'required|in:hourly,daily', 'level'=>'required|in:debug,info,warning,error',
            'debugMinutes'=>'required|in:15,30,60', 'rotationMiB'=>'required|integer|between:1,1024',
            'rotationFiles'=>'required|integer|between:1,100', 'archiveKeep'=>'required|in:forever,years',
            'compressionRatio'=>'required|integer|between:1,100'];
        foreach (['cleanup','usageEnabled','auditFailures','auditLogin','auditReads','compress','summaryDaily'] as $key) $rules[$key]='required|boolean';
        foreach (['archiveAfter','archiveYears','summaryUsers','estimateYears','horizonMinutes','horizonFailedDays','appliedHours'] as $key) $rules[$key]='required|integer|between:1,100000';
        $data = Validator::make($input, $rules)->validate();
        // Do not persist arbitrary UI metadata or unvalidated nested keys.
        $data['policies'] = array_map(fn($p)=>array_intersect_key($p,array_flip(['id','enabled','days','maxMiB','mode','daily','bytes'])), $data['policies']);
        foreach ($data['policies'] as $p) if ($p['id']==='events') abort_unless($p['enabled'],422,'配置同步事件必须启用。');
        $previous = self::get(true);
        $data['restoreLevel'] = self::level() === 'debug' ? $previous['restoreLevel'] : self::level();
        $data['debugUntil'] = $data['level']==='debug' ? time()+(int)$data['debugMinutes']*60 : null;
        Setting::createOrUpdate('log_policy', $data);
        Setting::createOrUpdate('usage_enabled', $data['usageEnabled']);
        // Flush only after the audit/version transaction successfully commits.
        DB::afterCommit(function () { self::forget(); app(\App\Support\Setting::class)->save([]); });
        return $data;
    }

    public static function forget(): void { self::$cached=[]; self::$readAt=0; self::$configured=false; }
}
