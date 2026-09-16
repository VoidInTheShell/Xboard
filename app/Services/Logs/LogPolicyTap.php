<?php
namespace App\Services\Logs;

use Monolog\Handler\HandlerWrapper;
use Monolog\Level;
use Monolog\LogRecord;

class LogPolicyTap
{
    public function __invoke($logger): void
    {
        $logger->setHandlers(array_map(function ($handler) {
            if (method_exists($handler,'setLevel')) $handler->setLevel(Level::Debug);
            return new class($handler) extends HandlerWrapper {
                public function isHandling(LogRecord $record): bool
                {
                    return LogSettings::enabled('app') && $record->level->value>=Level::fromName(LogSettings::level())->value && parent::isHandling($record);
                }
                public function handle(LogRecord $record): bool
                {
                    if (!$this->isHandling($record)) return false;
                    return parent::handle($record->with(message:LogRedactor::text($record->message,4096),context:[],extra:[]));
                }
                public function handleBatch(array $records): void { foreach ($records as $record) $this->handle($record); }
            };
        },$logger->getHandlers()));
    }
}
