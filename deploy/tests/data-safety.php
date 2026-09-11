<?php

declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

/** @return array{exit_code: int, stdout: string, stderr: string} */
function runCommand(array $arguments): array
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start child PHP process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function createSchema(SQLite3 $database): void
{
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('CREATE TABLE v2_server_group (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    $database->exec('CREATE TABLE v2_server_route (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remarks TEXT NOT NULL,
        match TEXT NOT NULL,
        action TEXT NOT NULL,
        action_value TEXT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    $database->exec('CREATE TABLE v2_server_machine (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        notes TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        last_seen_at INTEGER NULL,
        load_status TEXT NULL,
        xray_config TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $database->exec('CREATE TABLE v2_server (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        code TEXT NULL,
        parent_id INTEGER NULL,
        group_ids TEXT NULL,
        route_ids TEXT NULL,
        name TEXT NOT NULL,
        rate TEXT NOT NULL,
        tags TEXT NULL,
        host TEXT NOT NULL,
        port TEXT NOT NULL,
        server_port INTEGER NOT NULL,
        protocol_settings TEXT NULL,
        show INTEGER NOT NULL DEFAULT 0,
        sort INTEGER NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        machine_id INTEGER NULL,
        enabled INTEGER NULL DEFAULT 1,
        UNIQUE(type, code),
        FOREIGN KEY(machine_id) REFERENCES v2_server_machine(id) ON DELETE SET NULL
    )');
}

function createMergeFixture(string $path, bool $source): void
{
    $database = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    createSchema($database);
    $now = '2026-09-12 00:00:00';
    $database->exec("INSERT INTO v2_server_group (id, name, created_at, updated_at) VALUES (1, 'Access', 1, 1)");
    $database->exec("INSERT INTO v2_server_route (id, remarks, match, action, action_value, created_at, updated_at) VALUES (1, 'route', '[]', 'block', NULL, 1, 1)");

    if ($source) {
        $database->exec("INSERT INTO v2_server_machine (id, name, token, is_active, created_at, updated_at) VALUES (1, 'old-machine', 'old-machine-token', 1, '{$now}', '{$now}')");
        $database->exec("INSERT INTO v2_server (id, type, code, parent_id, group_ids, route_ids, name, rate, tags, host, port, server_port, protocol_settings, show, sort, created_at, updated_at, machine_id, enabled) VALUES (1, 'vless', 'shared-code', NULL, '[1]', '[1]', 'old-parent', '1.00', '[]', 'old.example', '443', 10001, '{}', 1, 1, '{$now}', '{$now}', 1, 1)");
        $database->exec("INSERT INTO v2_server (id, type, code, parent_id, group_ids, route_ids, name, rate, tags, host, port, server_port, protocol_settings, show, sort, created_at, updated_at, machine_id, enabled) VALUES (2, 'vless', 'old-child', 1, '[1]', '[1]', 'old-child', '1.00', '[]', 'child.example', '443', 10002, '{}', 1, 2, '{$now}', '{$now}', 1, 1)");
    } else {
        $database->exec("INSERT INTO v2_server_machine (id, name, token, is_active, created_at, updated_at) VALUES (1, 'current-machine', 'current-machine-token', 1, '{$now}', '{$now}')");
        $database->exec("INSERT INTO v2_server (id, type, code, parent_id, group_ids, route_ids, name, rate, tags, host, port, server_port, protocol_settings, show, sort, created_at, updated_at, machine_id, enabled) VALUES (1, 'vless', 'shared-code', NULL, '[1]', '[1]', 'current', '1.00', '[]', 'current.example', '443', 20001, '{}', 1, 1, '{$now}', '{$now}', 1, 1)");
    }
    $database->close();
}

$deployRoot = dirname(__DIR__);
$guard = $deployRoot . '/shared/sqlite-data-guard.php';
$merge = $deployRoot . '/production/recovery/merge-server-configuration.php';
$temporaryDirectory = sys_get_temp_dir() . '/xboard-data-safety-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700, true)) {
    throw new RuntimeException('could not create the temporary test directory');
}

try {
    $currentPath = $temporaryDirectory . '/current.sqlite';
    createMergeFixture($currentPath, false);
    $snapshotPath = $temporaryDirectory . '/snapshot.sqlite';

    $snapshot = runCommand([PHP_BINARY, $guard, 'snapshot', $currentPath, $snapshotPath]);
    assertSameValue(0, $snapshot['exit_code'], 'guard snapshot exit code');

    $current = new SQLite3($currentPath, SQLITE3_OPEN_READWRITE);
    $current->exec('DELETE FROM v2_server');
    $current->close();
    $regression = runCommand([PHP_BINARY, $guard, 'assert-not-decreased', $snapshotPath, $currentPath]);
    if ($regression['exit_code'] === 0) {
        throw new RuntimeException('guard accepted a protected-table regression');
    }

    $restore = runCommand([PHP_BINARY, $guard, 'restore', $snapshotPath, $currentPath]);
    assertSameValue(0, $restore['exit_code'], 'guard restore exit code');
    $postRestore = runCommand([PHP_BINARY, $guard, 'assert-not-decreased', $snapshotPath, $currentPath]);
    assertSameValue(0, $postRestore['exit_code'], 'guard post-restore assertion');

    $sourcePath = $temporaryDirectory . '/source.sqlite';
    $targetPath = $temporaryDirectory . '/target.sqlite';
    createMergeFixture($sourcePath, true);
    createMergeFixture($targetPath, false);

    $firstMerge = runCommand([PHP_BINARY, $merge, $sourcePath, $targetPath]);
    assertSameValue(0, $firstMerge['exit_code'], 'first merge exit code');
    $firstPayload = json_decode($firstMerge['stdout'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue(2, $firstPayload['inserted']['servers'], 'first merge server insertions');
    assertSameValue(1, $firstPayload['inserted']['machines'], 'first merge machine insertions');
    assertSameValue(3, $firstPayload['after']['servers'], 'first merge server total');
    assertSameValue(2, $firstPayload['after']['machines'], 'first merge machine total');

    $target = new SQLite3($targetPath, SQLITE3_OPEN_READONLY);
    $mappedParent = $target->querySingle("SELECT parent_id FROM v2_server WHERE name = 'old-child'");
    $oldParentId = $target->querySingle("SELECT id FROM v2_server WHERE name = 'old-parent'");
    assertSameValue((int) $oldParentId, (int) $mappedParent, 'parent ID remapping');
    assertSameValue('ok', $target->querySingle('PRAGMA quick_check'), 'merged database quick_check');
    $target->close();

    $secondMerge = runCommand([PHP_BINARY, $merge, $sourcePath, $targetPath]);
    assertSameValue(0, $secondMerge['exit_code'], 'second merge exit code');
    $secondPayload = json_decode($secondMerge['stdout'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue(0, array_sum($secondPayload['inserted']), 'second merge idempotency');
    assertSameValue($firstPayload['after'], $secondPayload['after'], 'second merge totals');

    echo "data-safety-tests=passed\n";
} finally {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($temporaryDirectory);
}
