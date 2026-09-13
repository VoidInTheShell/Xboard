<?php

namespace App\Services\Logs;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Bounded, process-locked file ring; stores a redacted structured summary. */
class PanelLogHandler extends AbstractProcessingHandler
{
    public function __construct(private string $category = 'app') { parent::__construct(Level::Debug); }

    protected function write(LogRecord $record): void
    {
        $policy = LogSettings::policy($this->category);
        if (!$policy['enabled'] || ($this->category==='app' && $record->level->value < Level::fromName(LogSettings::level())->value)) return;
        $settings = LogSettings::get();
        $message = LogRedactor::text($record->message, 4096);
        // Raw exception arguments, request bodies and queue payloads can contain
        // passwords, SMTP credentials or login links. Do not persist them.
        $context = array_intersect_key($record->context, array_flip(['id','user_id','node_id','machine_id','request_id','operation','status','code']));
        foreach ($context as $key=>$value) if (!is_scalar($value)) unset($context[$key]);
        $line = json_encode(['time'=>$record->datetime->getTimestamp(), 'level'=>$record->level->getName(),
            'message'=>$message, 'context'=>$context], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        $dir = storage_path('logs');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
        $lock = @fopen($dir.'/.panel-log.lock', 'c');
        if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX)) return;
            $path = $dir.'/managed-'.$this->category.'.log';
            if (is_link($path)) return;
            $cap = ($policy['mode']==='days'?$settings['rotationMiB']:min($settings['rotationMiB'], $policy['maxMiB'])) * 1048576;
            clearstatcache(true, $path);
            if (is_file($path) && (filesize($path)+strlen($line)>$cap || date('Y-m-d',filemtime($path))!==date('Y-m-d'))) {
                for ($i=$settings['rotationFiles']-1; $i>=1; $i--) {
                    $dest=$path.'.'.$i; $source=$i===1?$path:$path.'.'.($i-1);
                    if (is_link($dest)||is_link($source)) continue;
                    if (is_file($dest)) unlink($dest);
                    if (is_file($source)) rename($source,$dest);
                }
                if ($settings['rotationFiles']===1 && is_file($path)) unlink($path);
            }
            $files = app(LogFiles::class)->files($this->category);
            $used = array_sum(array_column($files,'bytes'));
            $limit = $policy['mode']==='days' ? PHP_INT_MAX : $policy['maxMiB']*1048576;
            // Admission safety remains in effect even if scheduled cleanup is off.
            if ($used+strlen($line)>$limit) return;
            if (array_sum(array_column(app(LogFiles::class)->files(),'bytes'))+strlen($line)>$settings['totalGiB']*1073741824) return;
            if (!LogBudget::accepts(strlen($line))) return;
            file_put_contents($path,$line,FILE_APPEND);
            @chmod($path,0640);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
