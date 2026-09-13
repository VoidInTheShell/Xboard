<?php

namespace App\Services\Logs;

use Illuminate\Support\Facades\DB;

class LogArchive
{
    /** Called in the same transaction as removing the corresponding detail rows. */
    public function accumulate(array $traffic): void
    {
        if (!LogSettings::get()['summaryDaily']) return;
        $rows=[];
        foreach ($traffic as $row) {
            $row=array_intersect_key($row,array_flip(['bucket','layer','user_id','node_id','machine_id','up','down','billed_up','billed_down']));
            $row['bucket']-=(($row['bucket']+28800)%86400);
            $key=implode(':',array_intersect_key($row,array_flip(['bucket','layer','user_id','node_id','machine_id'])));
            if (!isset($rows[$key])) $rows[$key]=$row;
            else foreach (['up','down','billed_up','billed_down'] as $col) $rows[$key][$col]+=$row[$col];
        }
        $updates=[];
        foreach (['up','down','billed_up','billed_down'] as $col) {
            $incoming=DB::getDriverName()==='mysql'?"VALUES($col)":"excluded.$col";
            $updates[$col]=DB::raw("$col + $incoming");
        }
        foreach (array_chunk(array_values($rows),100) as $chunk) DB::table('v2_log_daily')->upsert($chunk,['bucket','layer','user_id','node_id','machine_id'],$updates);
    }

    public function maintain(): void
    {
        $s=LogSettings::get();
        if ($s['compress']) {
            // Small atomic chunks: failure to compress/insert leaves original rows.
            DB::transaction(function () use ($s) {
                $bucket=DB::table('v2_log_daily')->where('bucket','<',time()-$s['archiveAfter']*86400)->min('bucket');
                if ($bucket===null) return;
                $rows=DB::table('v2_log_daily')->where('bucket',$bucket)->orderBy('id')->limit(1000)->lockForUpdate()->get();
                if ($rows->isEmpty()) return;
                $json=json_encode($rows->map(fn($r)=>array_diff_key((array)$r,['id'=>true]))->all(),JSON_THROW_ON_ERROR);
                $compressed=gzencode($json,6);
                if ($compressed===false || gzdecode($compressed)!==$json) throw new \RuntimeException('Archive verification failed');
                DB::table('v2_log_archive')->insert(['bucket'=>$bucket,'rows'=>$rows->count(),
                    'raw_bytes'=>strlen($json),'payload'=>base64_encode($compressed),'created_at'=>time()]);
                DB::table('v2_log_daily')->whereIn('id',$rows->pluck('id'))->delete();
            });
        }
        if ($s['cleanup'] && $s['archiveKeep']==='years') {
            foreach (['v2_log_daily','v2_log_archive'] as $table) {
                $ids=DB::table($table)->where('bucket','<',now()->subYears($s['archiveYears'])->timestamp)->orderBy('id')->limit(1000)->pluck('id');
                DB::table($table)->whereIn('id',$ids)->delete();
            }
        }
    }

    public function query(array $scope, int $from, int $to): array
    {
        $query=DB::table('v2_log_daily')->whereBetween('bucket',[$from,$to]);
        foreach (['user_id','node_id','machine_id'] as $key) if (!empty($scope[$key])) $query->where($key,$scope[$key]);
        $rows=$query->limit(10001)->get()->map(fn($r)=>(array)$r)->all();
        $archiveQuery=DB::table('v2_log_archive')->whereBetween('bucket',[$from,$to])->orderBy('id');
        abort_if((clone $archiveQuery)->count()>500,422,'请缩短归档查询时间范围。');
        $archives=$archiveQuery->cursor();
        $size=0;
        foreach ($archives as $archive) {
            $size+=strlen($archive->payload); abort_if($size>8*1048576,422,'请缩短归档查询时间范围。');
            $json=gzdecode(base64_decode($archive->payload,true),2*1048576);
            if ($json===false) throw new \RuntimeException('Unreadable log archive');
            foreach (json_decode($json,true,512,JSON_THROW_ON_ERROR) as $row) {
                foreach (['user_id','node_id','machine_id'] as $key) if (!empty($scope[$key]) && $row[$key]!=(int)$scope[$key]) continue 2;
                $rows[]=$row;
            }
            abort_if(count($rows)>10000,422,'请缩短时间范围或限定用户、节点。');
        }
        abort_if(count($rows)>10000,422,'请缩短时间范围或限定用户、节点。');
        return $rows;
    }
}
