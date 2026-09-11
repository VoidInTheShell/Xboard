<?php

declare(strict_types=1);

const PROTECTED_TABLES = [
    'v2_server',
    'v2_server_machine',
    'v2_server_group',
    'v2_server_route',
    'v2_outbound',
    'v2_user',
];

function fail(string $message): never
{
    fwrite(STDERR, "[sqlite-data-guard] ERROR: {$message}\n");
    exit(1);
}

function openDatabase(string $path, bool $writable): SQLite3
{
    if (!is_file($path)) {
        fail("database does not exist: {$path}");
    }

    $flags = $writable ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY;
    $database = new SQLite3($path, $flags);
    $database->busyTimeout(30000);

    return $database;
}

function quickCheck(SQLite3 $database): void
{
    $result = $database->querySingle('PRAGMA quick_check');
    if ($result !== 'ok') {
        fail('SQLite quick_check did not return ok');
    }
}

/** @return array<string, int|null> */
function protectedCounts(SQLite3 $database): array
{
    $counts = [];
    $tableStatement = $database->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    if ($tableStatement === false) {
        fail('could not prepare the table lookup');
    }

    foreach (PROTECTED_TABLES as $table) {
        $tableStatement->reset();
        $tableStatement->clear();
        $tableStatement->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $tableStatement->execute();
        if ($result === false) {
            fail("could not inspect table {$table}");
        }
        $exists = $result->fetchArray(SQLITE3_NUM) !== false;
        $result->finalize();

        if (!$exists) {
            $counts[$table] = null;
            continue;
        }

        $count = $database->querySingle(sprintf('SELECT COUNT(*) FROM "%s"', $table));
        if (!is_int($count)) {
            fail("could not count table {$table}");
        }
        $counts[$table] = $count;
    }

    return $counts;
}

/** @param array<string, mixed> $payload */
function report(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

function snapshot(string $sourcePath, string $destinationPath): never
{
    if (file_exists($destinationPath)) {
        fail("snapshot destination already exists: {$destinationPath}");
    }

    $destinationDirectory = dirname($destinationPath);
    if (!is_dir($destinationDirectory) || !is_writable($destinationDirectory)) {
        fail("snapshot directory is not writable: {$destinationDirectory}");
    }

    $source = openDatabase($sourcePath, true);
    quickCheck($source);
    $before = protectedCounts($source);

    // The application is stopped before this helper is called. A full
    // checkpoint makes the on-disk state self-contained, while SQLite's backup
    // API still supplies a transactionally consistent snapshot.
    $source->exec('PRAGMA wal_checkpoint(FULL)');
    $destination = new SQLite3(
        $destinationPath,
        SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
    );
    $destination->busyTimeout(30000);
    if (!$source->backup($destination)) {
        fail('SQLite online backup failed');
    }
    $destination->close();
    $source->close();
    chmod($destinationPath, 0600);

    $snapshot = openDatabase($destinationPath, false);
    quickCheck($snapshot);
    $after = protectedCounts($snapshot);
    $snapshot->close();
    if ($before !== $after) {
        fail('snapshot table counts do not match the source database');
    }

    report([
        'status' => 'ok',
        'operation' => 'snapshot',
        'counts' => $after,
    ]);
}

function verify(string $path): never
{
    $database = openDatabase($path, false);
    quickCheck($database);
    $counts = protectedCounts($database);
    $database->close();

    report([
        'status' => 'ok',
        'operation' => 'verify',
        'counts' => $counts,
    ]);
}

function assertNotDecreased(string $baselinePath, string $currentPath): never
{
    $baseline = openDatabase($baselinePath, false);
    $current = openDatabase($currentPath, false);
    quickCheck($baseline);
    quickCheck($current);
    $before = protectedCounts($baseline);
    $after = protectedCounts($current);
    $baseline->close();
    $current->close();

    $regressions = [];
    foreach ($before as $table => $count) {
        if ($count === null) {
            continue;
        }
        if ($after[$table] === null || $after[$table] < $count) {
            $regressions[$table] = [
                'before' => $count,
                'after' => $after[$table],
            ];
        }
    }
    if ($regressions !== []) {
        fail('protected business-row counts decreased: ' . json_encode($regressions, JSON_THROW_ON_ERROR));
    }

    report([
        'status' => 'ok',
        'operation' => 'assert-not-decreased',
        'before' => $before,
        'after' => $after,
    ]);
}

function restore(string $snapshotPath, string $targetPath): never
{
    $snapshot = openDatabase($snapshotPath, false);
    quickCheck($snapshot);
    $counts = protectedCounts($snapshot);
    $snapshot->close();

    $temporaryPath = $targetPath . '.restore-' . getmypid();
    if (file_exists($temporaryPath)) {
        fail("temporary restore path already exists: {$temporaryPath}");
    }
    if (!copy($snapshotPath, $temporaryPath)) {
        fail('could not copy the verified snapshot to the restore path');
    }
    chmod($temporaryPath, 0600);

    foreach ([$targetPath . '-wal', $targetPath . '-shm'] as $sidecar) {
        if (file_exists($sidecar) && !unlink($sidecar)) {
            @unlink($temporaryPath);
            fail("could not remove stale SQLite sidecar: {$sidecar}");
        }
    }
    if (!rename($temporaryPath, $targetPath)) {
        @unlink($temporaryPath);
        fail('could not atomically restore the verified snapshot');
    }

    $restored = openDatabase($targetPath, false);
    quickCheck($restored);
    if (protectedCounts($restored) !== $counts) {
        fail('restored database counts do not match the snapshot');
    }
    $restored->close();

    report([
        'status' => 'ok',
        'operation' => 'restore',
        'counts' => $counts,
    ]);
}

if (!class_exists(SQLite3::class)) {
    fail('the SQLite3 PHP extension is required');
}

$operation = $argv[1] ?? '';
try {
    match ($operation) {
        'snapshot' => count($argv) === 4
            ? snapshot($argv[2], $argv[3])
            : fail('usage: snapshot SOURCE_DB DESTINATION_DB'),
        'verify' => count($argv) === 3
            ? verify($argv[2])
            : fail('usage: verify DATABASE'),
        'assert-not-decreased' => count($argv) === 4
            ? assertNotDecreased($argv[2], $argv[3])
            : fail('usage: assert-not-decreased BASELINE_DB CURRENT_DB'),
        'restore' => count($argv) === 4
            ? restore($argv[2], $argv[3])
            : fail('usage: restore SNAPSHOT_DB TARGET_DB'),
        default => fail('operation must be snapshot, verify, assert-not-decreased, or restore'),
    };
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
