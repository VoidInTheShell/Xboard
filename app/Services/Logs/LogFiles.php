<?php

namespace App\Services\Logs;

class LogFiles
{
    public function files(?string $category = null): array
    {
        $result=[];
        foreach (glob(storage_path('logs/*')) ?: [] as $path) {
            if (!is_file($path)||is_link($path)) continue;
            $name=basename($path); $kind=null;
            foreach (['app'=>'laravel','backup'=>'backup','deprecation'=>'deprecations'] as $key=>$prefix) {
                if (preg_match('/^(?:'.preg_quote($prefix,'/').'(?:-\d{4}-\d{2}-\d{2})?\.log|managed-'.$key.'\.log(?:\.\d{1,2})?)$/D',$name)) $kind=$key;
            }
            if (!$kind || ($category && $category!==$kind)) continue;
            clearstatcache(true,$path);
            $result[]=['path'=>$path,'category'=>$kind,'bytes'=>filesize($path),'modified'=>filemtime($path)];
        }
        usort($result,fn($a,$b)=>$a['modified']<=>$b['modified']);
        return $result;
    }

    /** Read at most 2 MiB total, newest tails first, without user-supplied paths. */
    public function records(?string $category = null): array
    {
        $remaining=2*1048576; $records=[];
        foreach (array_reverse($this->files($category)) as $file) {
            if ($remaining<=0) break;
            $length=min($remaining,$file['bytes']); $remaining-=$length;
            $handle=fopen($file['path'],'rb');
            if (!$handle) continue;
            $offset=max(0,$file['bytes']-$length); fseek($handle,$offset);
            $body=stream_get_contents($handle,$length); fclose($handle);
            $lines=explode("\n",$body ?: ''); if ($offset>0) array_shift($lines);
            foreach ($lines as $i=>$line) {
                $row=json_decode($line,true);
                if (is_array($row) && isset($row['time'],$row['level'],$row['message'])) {
                    $at=(int)$row['time']; $level=$row['level']; $message=$row['message'];
                } elseif (preg_match('/^\[([^\]]+)\]\s+[^.]+\.(\w+):\s*(.*)$/',$line,$m)) {
                    $at=strtotime($m[1])?:$file['modified']; $level=$m[2]; $message=$m[3];
                    // Legacy Laravel context can embed entire mail configuration.
                    $message=preg_split('/\s+\{/', $message,2)[0];
                } else continue;
                $text=LogRedactor::text($message,4096);
                $records[]=['id'=>basename($file['path']).':'.$offset.':'.$i,'at'=>$at,
                    'source'=>['app'=>'应用','backup'=>'备份','deprecation'=>'弃用警告'][$file['category']],
                    'target'=>'面板文件','title'=>mb_substr($text,0,180),'status'=>ucfirst(strtolower($level)),'detail'=>$text];
            }
        }
        return $records;
    }
}
