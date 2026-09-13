<?php

namespace App\Services\Logs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LogStorage
{
    public const TABLES = [
        'audit'=>[['v2_admin_audit_log','created_at']], 'mail'=>[['v2_mail_log','created_at']],
        'reset'=>[['v2_traffic_reset_logs','reset_time']], 'events'=>[['v2_change_event','created_at']],
        'web'=>[['v2_usage_event','recorded_at','kind','panel']],
        'subscription'=>[['v2_usage_event','recorded_at','kind','subscription']],
        'source'=>[['v2_usage_source','last_seen'],['v2_usage_identity','last_seen']],
        'review'=>[['v2_usage_review','reviewed_at']],
        'traffic'=>[['v2_usage_traffic','bucket','layer','proxy']],
        'nic'=>[['v2_usage_traffic','bucket','layer',['nic','instance']]],
        'ip'=>[['v2_usage_ip_traffic','bucket']],
        'online'=>[['v2_usage_online_history','bucket'],['v2_usage_online_scope_history','bucket']],
        'legacy'=>[['v2_stat_user','record_at'],['v2_stat_server','record_at']],
        'queue'=>[['failed_jobs','failed_at']], 'load'=>[['v2_server_machine_load_history','recorded_at']],
        'archive'=>[['v2_log_daily','bucket'],['v2_log_archive','bucket']],
    ];

    public function query(array $definition)
    {
        $query=DB::table($definition[0]);
        if (isset($definition[2])) $query->whereIn($definition[2],(array)$definition[3]);
        return $query;
    }

    public function measure(): array
    {
        $categories=[];
        foreach (self::TABLES as $category=>$tables) {
            $bytes=0; $rows=0; $oldest=null; $newest=null;
            foreach ($tables as $def) {
                $query=$this->query($def); $count=(clone $query)->count(); $rows+=$count;
                if (!$count) continue;
                $grammar=DB::connection()->getQueryGrammar();
                $columns=\Illuminate\Support\Facades\Schema::getColumnListing($def[0]);
                $expression=implode(' + ',array_map(fn($column)=>'COALESCE(LENGTH(CAST('.$grammar->wrap($column).' AS CHAR)),0)',$columns));
                $sample=(clone $query)->selectRaw($expression.' AS row_bytes')->orderByDesc('id')->limit(100);
                // Measure lengths in SQL rather than loading old multi-megabyte
                // failed-job payloads into PHP. Forecast form inputs are unrelated.
                $average=max(128,(int)ceil(((float)DB::query()->fromSub($sample,'sample')->avg('row_bytes')+96)*1.5));
                $bytes+=$count*$average;
                $min=(clone $query)->min($def[1]); $max=(clone $query)->max($def[1]);
                $oldest=$oldest===null?$min:min($oldest,$min); $newest=$newest===null?$max:max($newest,$max);
            }
            $categories[$category]=['bytes'=>$bytes,'rows'=>$rows,'oldest'=>$oldest,'newest'=>$newest,'estimated'=>true];
        }
        foreach (['app','backup','deprecation'] as $id) {
            $files=app(LogFiles::class)->files($id);
            $categories[$id]=['bytes'=>array_sum(array_column($files,'bytes')),'files'=>count($files),'estimated'=>false];
        }
        $state=['bytes'=>array_sum(array_column($categories,'bytes')),'categories'=>$categories,'measuredAt'=>time()];
        Cache::lock('logs:admission',5)->block(1,function () use ($state) {
            Cache::put('logs:storage-state',$state,86400);
            Cache::forget('logs:reserved-bytes');
        });
        return $state;
    }

    public function status(): array
    {
        return ['storage'=>Cache::get('logs:storage-state'), 'maintenance'=>Cache::get('logs:maintenance'),
            'effectiveLevel'=>LogSettings::level(), 'debugUntil'=>LogSettings::get()['debugUntil'],
            'channel'=>config('logging.default'),
            'scope'=>'面板历史日志数据库估算占用、日志文件及轻量归档；不含业务表、实时快照、计数游标、Redis、备份文件、Docker、journal 或节点日志。',
            'legacyDependencies'=>['首页流量统计','用户流量报表','流量排行榜']];
    }

    public function maintain(bool $force = false): array
    {
        $expired=DB::table('v2_usage_online')->where('sampled_at','<',time()-600)->limit(5000)->pluck('id');
        DB::table('v2_usage_online')->whereIn('id',$expired)->delete();
        $s=LogSettings::get(true); $last=Cache::get('logs:maintenance');
        $due=$force || !$last || time()-($last['cleanedAt']??0)>=($s['interval']==='daily'?86400:3600);
        $state=$this->measure(); $removed=0;
        // High-water budget triggers cleanup independently of the normal interval.
        $over=$state['bytes']>$s['totalGiB']*1073741824;
        foreach ($s['policies'] as $p) if ($p['mode']!=='days' && ($state['categories'][$p['id']]['bytes']??0)>$p['maxMiB']*1048576) $due=true;
        if ($s['cleanup'] && ($due||$over)) {
            $this->pruneInternalState();
            foreach ($s['policies'] as $p) {
                if ($p['id']==='source' && !$p['enabled']) { $p['days']=1; $p['mode']='either'; }
                if (in_array($p['id'],['app','backup','deprecation'],true)) continue;
                foreach (self::TABLES[$p['id']]??[] as $def) {
                    for ($batch=0;$batch<4;$batch++) {
                        $q=$this->eligible($def,$p['id']);
                        if ($p['mode']!=='size') $q->where($def[1],'<',in_array($def[0],['failed_jobs','v2_traffic_reset_logs'],true)?date('Y-m-d H:i:s',time()-$p['days']*86400):time()-$p['days']*86400);
                        else break;
                        $n=$this->remove($q,$def[0]); $removed+=$n; if ($n<1000) break;
                    }
                }
            }
            $this->cleanFiles(false);
            $state=$this->measure();
            // Prioritize frequent, reproducible records over small daily summaries.
            foreach (['audit','mail','web','subscription','queue','reset','load','ip','online','legacy','traffic','nic','review','source','events'] as $id) {
                $p=LogSettings::policy($id); $categoryBytes=$state['categories'][$id]['bytes']??0;
                $cap=$p['mode']==='days'?PHP_INT_MAX:$p['maxMiB']*1048576;
                $target=$s['totalGiB']*1073741824*$s['watermark']/100;
                for ($batch=0;$batch<8 && ($categoryBytes>$cap || ($over && $state['bytes']>$target));$batch++) {
                    $count=0;
                    $average=max(128,$categoryBytes/max(1,$state['categories'][$id]['rows']??1));
                    $needed=max(0,$categoryBytes-$cap,$over?$state['bytes']-$target:0);
                    $limit=max(1,min(1000,(int)ceil($needed/$average)));
                    foreach (self::TABLES[$id] as $def) $count+=$this->remove($this->eligible($def,$id),$def[0],$limit);
                    $removed+=$count; if (!$count) break;
                    $state=$this->measure(); $categoryBytes=$state['categories'][$id]['bytes']??0;
                }
            }
            if ($over) $this->cleanFiles(true);
            $removed+=$this->pruneOrphanReviews();
        }
        app(LogArchive::class)->maintain();
        $state=$this->measure();
        $result=['ranAt'=>time(),'cleanedAt'=>$s['cleanup']&&($due||$over)?time():($last['cleanedAt']??0),
            'removedRows'=>$removed,'overBudget'=>$state['bytes']>$s['totalGiB']*1073741824,
            'bytes'=>$state['bytes'],'budgetBytes'=>$s['totalGiB']*1073741824];
        Cache::put('logs:maintenance',$result,86400*30);
        return $result;
    }

    private function eligible(array $def, string $id)
    {
        $q=$this->query($def);
        if ($id==='events') $q->where('version','<',max(0,(int)DB::table('v2_change_state')->where('id',1)->value('version')-10000));
        if ($def[0]==='v2_usage_source') $q->whereNotIn('id',DB::table('v2_usage_online')->where('sampled_at','>=',time()-600)->select('source_id'));
        if ($def[0]==='v2_usage_identity') $q->whereNotIn('id',DB::table('v2_usage_source')->select('identity_id'));
        // A current hour/day may still receive increments. Preserve it for reports.
        if (in_array($id,['traffic','nic','ip','online','legacy'],true)) $q->where($def[1],'<',time()-86400);
        return $q->orderBy($def[1]);
    }

    private function pruneInternalState(): void
    {
        // Baselines and epoch fences are not historical logs. Keep current
        // streams, and retain inactive fences for at least 400 days.
        $before=time()-max(400,LogSettings::policy('traffic')['days'],LogSettings::policy('ip')['days'])*86400;
        $streams=DB::table('v2_usage_stream as s')->where('s.sampled_at','<',$before)
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('v2_usage_head as h')
                ->whereColumn('h.scope','s.scope')->whereColumn('h.source_id','s.source_id')->whereColumn('h.epoch','s.epoch'))
            ->orderBy('s.id')->limit(1000)->pluck('s.id');
        DB::transaction(function () use ($streams) {
            DB::table('v2_usage_counter')->whereIn('stream_id',$streams)->delete();
            DB::table('v2_usage_stream')->whereIn('id',$streams)->delete();
        });
        $ids=DB::table('v2_usage_ip_counter')->where('sampled_at','<',$before)->limit(1000)->pluck('id');
        DB::table('v2_usage_ip_counter')->whereIn('id',$ids)->delete();
        // Some older installations added a mail config column outside the base
        // migration. Remove its contents in bounded batches during maintenance.
        if (\Illuminate\Support\Facades\Schema::hasColumn('v2_mail_log','config')) {
            $ids=DB::table('v2_mail_log')->whereNotNull('config')->where('config','<>','')->orderBy('id')->limit(1000)->pluck('id');
            DB::table('v2_mail_log')->whereIn('id',$ids)->update(['config'=>'']);
        }
    }

    private function pruneOrphanReviews(): int
    {
        $cursor=(int)Cache::get('logs:review-prune-cursor',0);
        $reviews=DB::table('v2_usage_review')->where('id','>',$cursor)->orderBy('id')->limit(1000)->get();
        Cache::put('logs:review-prune-cursor',$reviews->count()===1000?(int)$reviews->last()->id:0,86400*30);
        $sourceIds=[]; $eventIds=[];
        foreach ($reviews as $r) {
            [$kind,$id]=array_pad(explode(':',$r->signal,2),2,'');
            if ($kind==='connection') $sourceIds[]=(int)$id; else $eventIds[]=(int)$id;
        }
        $found=[];
        foreach (DB::table('v2_usage_source')->whereIn('id',$sourceIds)->pluck('id') as $id) $found['connection:'.$id]=true;
        foreach (DB::table('v2_usage_event')->whereIn('id',$eventIds)->get(['id','kind']) as $r) $found[$r->kind.':'.$r->id]=true;
        return DB::table('v2_usage_review')->whereIn('id',$reviews->filter(fn($r)=>!isset($found[$r->signal]))->pluck('id'))->delete();
    }

    private function remove($query, string $table, int $limit = 1000): int
    {
        $ids=$query->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) return 0;
        return DB::transaction(function () use ($ids,$table) {
            if ($table==='v2_usage_traffic') {
                $rows=DB::table($table)->whereIn('id',$ids)->lockForUpdate()->get();
                app(LogArchive::class)->accumulate($rows->map(fn($r)=>(array)$r)->all());
            }
            if ($table==='v2_usage_source') DB::table('v2_usage_online')->whereIn('source_id',$ids)->where('sampled_at','<',time()-600)->delete();
            return DB::table($table)->whereIn('id',$ids)->delete();
        });
    }

    private function cleanFiles(bool $budget): void
    {
        $dir=storage_path('logs'); if (!is_dir($dir)) return;
        $lock=fopen($dir.'/.panel-log.lock','c'); if (!$lock) return;
        try {
            flock($lock,LOCK_EX);
            $all=app(LogFiles::class)->files(); $used=array_sum(array_column($all,'bytes'));
            $s=LogSettings::get(); $state=Cache::get('logs:storage-state');
            $others=max(0,($state['bytes']??0)-$used);
            foreach (['app','backup','deprecation'] as $id) {
                $p=LogSettings::policy($id); $files=array_values(array_filter($all,fn($f)=>$f['category']===$id));
                $categoryBytes=array_sum(array_column($files,'bytes')); $count=count($files);
                foreach ($files as $file) {
                    $expired=$p['mode']!=='size' && $file['modified']<time()-$p['days']*86400;
                    $large=$p['mode']!=='days' && $categoryBytes>$p['maxMiB']*1048576;
                    if (!$expired&&!$large&&$count<=$s['rotationFiles']&&!($budget&&$used+$others>$s['totalGiB']*1073741824*$s['watermark']/100)) continue;
                    // Never unlink a legacy open stream. Truncate under the shared
                    // lock so single-file writers continue using the same inode.
                    if (preg_match('/^(laravel|backup|deprecations|managed-[a-z]+)\.log$/',basename($file['path']))) {
                        $h=fopen($file['path'],'c'); if ($h) {flock($h,LOCK_EX); ftruncate($h,0); flock($h,LOCK_UN); fclose($h);}
                    } else { unlink($file['path']); $count--; }
                    $categoryBytes-=$file['bytes']; $used-=$file['bytes'];
                }
            }
        } finally {flock($lock,LOCK_UN); fclose($lock);}
    }
}
