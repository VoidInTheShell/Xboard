<?php
namespace App\Services\Logs;

use Illuminate\Queue\Failed\{FailedJobProviderInterface,CountableFailedJobProvider,PrunableFailedJobProvider};

class LogFailedJobProvider implements FailedJobProviderInterface, CountableFailedJobProvider, PrunableFailedJobProvider
{
    public function __construct(private FailedJobProviderInterface $inner) {}
    public function log($connection,$queue,$payload,$exception)
    {
        if (!LogSettings::enabled('queue') || !LogBudget::accepts(strlen($payload))) return null;
        // Preserve the private retry payload; the log query API never exposes it.
        return $this->inner->log($connection,$queue,$payload,new \RuntimeException(LogRedactor::text($exception->getMessage(),4096)));
    }
    public function ids($queue=null) {return $this->inner->ids($queue);}
    public function all() {return $this->inner->all();}
    public function find($id) {return $this->inner->find($id);}
    public function forget($id) {return $this->inner->forget($id);}
    public function flush($hours=null) {return $this->inner->flush($hours);}
    public function count($connection=null,$queue=null) {return $this->inner instanceof CountableFailedJobProvider?$this->inner->count($connection,$queue):count($this->inner->all());}
    public function prune(\DateTimeInterface $before) {return $this->inner instanceof PrunableFailedJobProvider?$this->inner->prune($before):0;}
}
