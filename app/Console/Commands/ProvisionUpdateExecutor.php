<?php
namespace App\Console\Commands;

use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use App\Services\Updates\ReleaseCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionUpdateExecutor extends Command
{
    protected $signature = 'update:executor {kind : panel or node} {--machine-id=} {--name=} {--updater-version=} {--installation-method=} {--rotate} {--disable} {--resume : Clear the recovery lock after manually verifying host and database health}';
    protected $description = 'Provision or revoke a host update executor; keep the issued credential only on that host';
    public function handle(): int
    {
        $kind = $this->argument('kind');
        if (!in_array($kind, ['panel', 'node'], true)) { $this->error('kind must be panel or node'); return 1; }
        $machine = $kind === 'node' ? ServerMachine::find($this->option('machine-id')) : null;
        if ($kind === 'node' && !$machine) { $this->error('A valid --machine-id is required'); return 1; }
        $scope = $kind === 'panel' ? 'panel' : 'machine:' . $machine->id;
        $executor = UpdateExecutor::where('scope', $scope)->first();
        if ($this->option('resume')) {
            if (!$executor) return 1;
            $executor->update(['blocked' => false]);
            $this->info('Recovery lock cleared.'); return 0;
        }
        if ($this->option('disable')) {
            if ($executor) $executor->update(['enabled' => false]);
            $this->info('Executor disabled.'); return 0;
        }
        if ($executor && !$this->option('rotate')) { $this->error('Already provisioned; use --rotate to replace the credential.'); return 1; }
        $updaterVersion = $this->option('updater-version');
        if ($updaterVersion !== null && ReleaseCatalog::channel($updaterVersion) === null) {
            $this->error('updater-version must be an exact release version.');
            return 1;
        }
        $installationMethod = $this->option('installation-method');
        if ($installationMethod !== null && !in_array($installationMethod, ['systemd', 'docker', 'compose'], true)) {
            $this->error('installation-method must be systemd, docker or compose.');
            return 1;
        }
        $secret = 'xbu_' . Str::random(64);
        $executor = UpdateExecutor::updateOrCreate(['scope' => $scope], [
            'id' => $executor?->id ?? (string) Str::uuid(), 'kind' => $kind, 'machine_id' => $machine?->id,
            'name' => $this->option('name') ?: ($machine?->name ?? '当前面板'),
            'enabled' => true, 'secret_hash' => hash('sha256', $secret),
            'protocol' => 2, 'state_schema' => 1, 'updater_version' => $updaterVersion,
            'installation_method' => $installationMethod,
        ]);
        $this->line(json_encode(['executor_id' => $executor->id, 'token' => $secret], JSON_UNESCAPED_SLASHES));
        return 0;
    }
}
