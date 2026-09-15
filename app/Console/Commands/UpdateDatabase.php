<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class UpdateDatabase extends Command
{
    protected $signature = 'update:database {action : backup, restore or verify} {task_id}';
    protected $description = 'Database backup/recovery for the independent host updater, while application writers are stopped';
    public function handle(): int
    {
        $id = $this->argument('task_id');
        if (!preg_match('/\\A[0-9a-f-]{36}\\z/i', $id)) { $this->error('Invalid task ID'); return 1; }
        if (!is_file(base_path('.docker/.data/update-maintenance'))) { $this->error('Update maintenance gate is required'); return 1; }
        $root = base_path('.docker/.data/update-backups');
        if (!is_dir($root) && !mkdir($root, 0700, true)) return 1;
        $driver = config('database.default');
        $config = config("database.connections.{$driver}");
        $path = "{$root}/{$id}." . ($driver === 'sqlite' ? 'sqlite' : 'sql');
        try {
            if ($this->argument('action') === 'verify') {
                DB::select('SELECT 1');
                if ($driver === 'sqlite' && (array_values((array) DB::select('PRAGMA integrity_check')[0])[0] ?? '') !== 'ok') throw new \RuntimeException();
                $this->info('Database verification passed'); return 0;
            }
            if ($driver === 'sqlite') {
                if ($this->argument('action') === 'backup') {
                    if (file_exists($path)) throw new \RuntimeException('Backup already exists');
                    DB::statement('VACUUM INTO ' . DB::connection()->getPdo()->quote($path));
                    chmod($path, 0600);
                } elseif ($this->argument('action') === 'restore') {
                    if (!is_file($path) || filesize($path) === 0) throw new \RuntimeException();
                    $database = $config['database'];
                    DB::disconnect();
                    // Writers are stopped by the host executor; do not replay the newer WAL after restoration.
                    foreach ([$database . '-wal', $database . '-shm'] as $sidecar) if (is_file($sidecar) && !unlink($sidecar)) throw new \RuntimeException();
                    if (!copy($path, $database . '.update-restore') || !rename($database . '.update-restore', $database)) throw new \RuntimeException();
                    chmod($database, 0660);
                    if (function_exists('posix_getpwnam') && ($www = posix_getpwnam('www'))) { chown($database, $www['uid']); chgrp($database, $www['gid']); }
                } else throw new \RuntimeException('Unknown action');
            } elseif ($driver === 'mysql') {
                if ($this->argument('action') === 'backup') {
                    if (file_exists($path)) throw new \RuntimeException('Backup already exists');
                    \Spatie\DbDumper\Databases\MySql::create()->setHost($config['host'])->setPort($config['port'])
                        ->setDbName($config['database'])->setUserName($config['username'])->setPassword($config['password'])
                        ->dumpToFile($path);
                    chmod($path, 0600);
                } elseif ($this->argument('action') === 'restore') {
                    if (!is_file($path) || filesize($path) === 0) throw new \RuntimeException();
                    $credentials = tempnam($root, 'mysql-');
                    try {
                        chmod($credentials, 0600);
                        $escape = fn ($value) => str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string) $value);
                        file_put_contents($credentials, "[client]\nuser=\"" . $escape($config['username']) . "\"\npassword=\"" . $escape($config['password']) . "\"\n");
                        // A dump alone does not remove tables introduced by a failed migration.
                        // Restrict cleanup to this connection's database, with writers still gated.
                        $tables = DB::select('SHOW FULL TABLES');
                        DB::statement('SET FOREIGN_KEY_CHECKS=0');
                        try {
                            foreach ($tables as $table) {
                                $values = array_values((array) $table);
                                $identifier = '`' . str_replace('`', '``', $values[0]) . '`';
                                DB::statement(($values[1] === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . $identifier);
                            }
                        } finally { DB::statement('SET FOREIGN_KEY_CHECKS=1'); }
                        $process = new Process(['mysql', '--defaults-extra-file=' . $credentials, '--host=' . $config['host'],
                            '--port=' . $config['port'], $config['database']], null, null, fopen($path, 'rb'), 600);
                        $process->disableOutput()->mustRun();
                    } finally { if (is_file($credentials)) unlink($credentials); }
                } else throw new \RuntimeException('Unknown action');
            } else throw new \RuntimeException('Only sqlite and mysql are supported');
            $this->info('Database operation completed'); return 0;
        } catch (\Throwable) {
            // Never put database credentials or raw SQL errors into public update logs.
            $this->error('Database operation failed; preserve the backup and maintenance gate for recovery.');
            return 1;
        }
    }
}
