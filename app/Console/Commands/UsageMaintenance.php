<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UsageMaintenance extends Command
{
    protected $signature = 'usage:maintain {--prune : Delete expired observation data in bounded batches}';
    protected $description = 'Aggregate online peaks and prune usage observation data without touching billing';

    public function handle(): int
    {
        $this->prune('v2_usage_online', 'sampled_at', time() - 600);
        if ($this->option('prune')) return $this->call('logs:maintain');
        if (!\App\Services\Usage\UsageSettings::get('enabled')) return self::SUCCESS;
        if (!\App\Services\Logs\LogSettings::enabled('online') || !\App\Services\Logs\LogBudget::accepts()) return self::SUCCESS;
        $now = time();
        $known = app(\App\Services\Usage\UsageQueryService::class)->onlineCounts();
        foreach (['nodes' => 'node', 'machines' => 'machine'] as $key => $scope) {
            $rows = [];
            foreach ($known[$key] as $id => $counts) $rows[] = [
                'scope' => $scope, 'scope_id' => $id, 'bucket' => intdiv($now, 3600) * 3600, 'users' => 0, 'devices' => 0,
            ];
            foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_usage_online_history')->insertOrIgnore($chunk);
        }
        $base = DB::table('v2_usage_online')->where('sampled_at', '>=', $now - config('usage.online_ttl'));
        $knownNodes = array_keys($known['nodes']);
        $knownMachines = array_keys($known['machines']);
        $fleetComplete = !DB::table('v2_server')->where('enabled', true)->whereNotIn('id', $knownNodes)->exists();
        foreach (['node_id' => 'node', 'machine_id' => 'machine', 'user_id' => 'user', null => 'global'] as $column => $scope) {
            // Missing reports are unknown, never an observed zero/partial peak.
            if (in_array($scope, ['global', 'user'], true) && !$fleetComplete) continue;
            $q = clone $base;
            if ($scope === 'node') $q->whereIn('node_id', $knownNodes);
            if ($scope === 'machine') $q->whereIn('machine_id', $knownMachines);
            if ($column) $q->select("$column as scope_id")->groupBy($column);
            else $q->selectRaw('0 as scope_id');
            $q->selectRaw('COUNT(DISTINCT user_id) as users, COUNT(DISTINCT source_id) as devices');
            foreach ($q->get() as $row) {
                $key = ['scope' => $scope, 'scope_id' => $row->scope_id, 'bucket' => intdiv($now, 3600) * 3600];
                DB::table('v2_usage_online_history')->insertOrIgnore($key);
                DB::table('v2_usage_online_history')->where($key)->update([
                    'users' => DB::raw('CASE WHEN users < ' . (int) $row->users . ' THEN ' . (int) $row->users . ' ELSE users END'),
                    'devices' => DB::raw('CASE WHEN devices < ' . (int) $row->devices . ' THEN ' . (int) $row->devices . ' ELSE devices END'),
                ]);
            }
        }
        // Remove stale snapshots independently of history retention.
        // Composite scopes are counted from actual source identities before
        // aggregation. Summing node counts would double-count shared sources.
        foreach ([['user_id', 'node_id', 'machine_id'], ['user_id', 'node_id'], ['user_id', 'machine_id'], ['node_id', 'machine_id']] as $columns) {
            $q = (clone $base)->select($columns)->groupBy($columns)
                ->selectRaw('COUNT(DISTINCT user_id) as users, COUNT(DISTINCT source_id) as devices');
            if (in_array('node_id', $columns, true)) $q->whereIn('node_id', $knownNodes);
            else $q->whereIn('machine_id', $knownMachines);
            $rows = $q->get()->map(fn($r) => [
                'user_id' => $r->user_id ?? 0, 'node_id' => $r->node_id ?? 0, 'machine_id' => $r->machine_id ?? 0,
                'bucket' => intdiv($now, 3600) * 3600, 'users' => $r->users, 'devices' => $r->devices,
            ])->all();
            $updates = [];
            foreach (['users', 'devices'] as $column) {
                $incoming = DB::getDriverName() === 'mysql' ? "VALUES($column)" : "excluded.$column";
                $updates[$column] = DB::raw("CASE WHEN $column < $incoming THEN $incoming ELSE $column END");
            }
            foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_usage_online_scope_history')->upsert($chunk,
                ['user_id', 'node_id', 'machine_id', 'bucket'], $updates);
        }
        $this->prune('v2_usage_online', 'sampled_at', $now - 600);
        return self::SUCCESS;
    }

    private function prune(string $table, string $column, int $before): void
    {
        // Bound each scheduler run; indexed IDs prevent long full-table deletes.
        $ids = DB::table($table)->where($column, '<', $before)->orderBy('id')->limit(5000)->pluck('id');
        DB::table($table)->whereIn('id', $ids)->delete();
    }
}
