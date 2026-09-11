<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "[merge-server-configuration] ERROR: {$message}\n");
    exit(1);
}

function connect(string $path, bool $writable): PDO
{
    if (!is_file($path)) {
        fail("database does not exist: {$path}");
    }

    $database = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ]);
    $database->exec('PRAGMA busy_timeout = 30000');
    if ($writable) {
        $database->exec('PRAGMA foreign_keys = ON');
    } else {
        $database->exec('PRAGMA query_only = ON');
    }

    return $database;
}

function quickCheck(PDO $database): void
{
    if ($database->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
        fail('SQLite quick_check did not return ok');
    }
}

/** @return list<string> */
function columns(PDO $database, string $table): array
{
    $statement = $database->query(sprintf('PRAGMA table_info("%s")', $table));
    $columns = array_map(
        static fn (array $column): string => (string) $column['name'],
        $statement->fetchAll()
    );
    if ($columns === []) {
        fail("required table is missing: {$table}");
    }

    return $columns;
}

/** @return list<array<string, mixed>> */
function rows(PDO $database, string $table): array
{
    return $database->query(sprintf('SELECT * FROM "%s" ORDER BY id', $table))->fetchAll();
}

/** @param array<string, mixed> $row */
function insertRow(PDO $database, string $table, array $row, array $allowedColumns): int
{
    $payload = array_intersect_key($row, array_flip($allowedColumns));
    unset($payload['id']);
    if ($payload === []) {
        fail("no compatible columns are available for {$table}");
    }

    $names = array_keys($payload);
    $quoted = array_map(static fn (string $name): string => '"' . $name . '"', $names);
    $parameters = array_map(static fn (string $name): string => ':' . $name, $names);
    $statement = $database->prepare(sprintf(
        'INSERT INTO "%s" (%s) VALUES (%s)',
        $table,
        implode(', ', $quoted),
        implode(', ', $parameters)
    ));
    foreach ($payload as $name => $value) {
        $statement->bindValue(':' . $name, $value);
    }
    $statement->execute();

    return (int) $database->lastInsertId();
}

/** @param array<string, mixed> $row */
function identity(array $row, array $fields): string
{
    $payload = [];
    foreach ($fields as $field) {
        $payload[$field] = $row[$field] ?? null;
    }

    return hash('sha256', serialize($payload));
}

/** @param array<int, int> $mapping */
function remapJsonIds(mixed $value, array $mapping, string $field): mixed
{
    if ($value === null || $value === '') {
        return $value;
    }
    $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        fail("{$field} is not a JSON array");
    }

    $remapped = [];
    foreach ($decoded as $id) {
        $sourceId = filter_var($id, FILTER_VALIDATE_INT);
        if ($sourceId === false || !isset($mapping[(int) $sourceId])) {
            fail("{$field} references an unmapped ID");
        }
        $remapped[] = (string) $mapping[(int) $sourceId];
    }

    return json_encode(array_values(array_unique($remapped)), JSON_THROW_ON_ERROR);
}

function tableCount(PDO $database, string $table): int
{
    return (int) $database->query(sprintf('SELECT COUNT(*) FROM "%s"', $table))->fetchColumn();
}

if (count($argv) !== 3) {
    fail('usage: merge-server-configuration.php SOURCE_DB TARGET_DB');
}
if (realpath($argv[1]) !== false && realpath($argv[1]) === realpath($argv[2])) {
    fail('source and target databases must be different files');
}

try {
    $source = connect($argv[1], false);
    $target = connect($argv[2], true);
    quickCheck($source);
    quickCheck($target);

    $tables = ['v2_server_group', 'v2_server_route', 'v2_server_machine', 'v2_server'];
    $sourceColumns = [];
    $targetColumns = [];
    foreach ($tables as $table) {
        $sourceColumns[$table] = columns($source, $table);
        $targetColumns[$table] = columns($target, $table);
    }

    $before = [
        'servers' => tableCount($target, 'v2_server'),
        'machines' => tableCount($target, 'v2_server_machine'),
        'groups' => tableCount($target, 'v2_server_group'),
        'routes' => tableCount($target, 'v2_server_route'),
    ];
    $inserted = ['servers' => 0, 'machines' => 0, 'groups' => 0, 'routes' => 0];
    $target->beginTransaction();

    $groupMap = [];
    $groupLookup = $target->prepare('SELECT id FROM v2_server_group WHERE name = :name ORDER BY id LIMIT 1');
    foreach (rows($source, 'v2_server_group') as $row) {
        $groupLookup->execute([':name' => $row['name']]);
        $targetId = $groupLookup->fetchColumn();
        if ($targetId === false) {
            $targetId = insertRow($target, 'v2_server_group', $row, $targetColumns['v2_server_group']);
            $inserted['groups']++;
        }
        $groupMap[(int) $row['id']] = (int) $targetId;
    }

    $routeIdentityFields = array_values(array_diff(
        array_intersect($sourceColumns['v2_server_route'], $targetColumns['v2_server_route']),
        ['id', 'created_at', 'updated_at']
    ));
    $routeByIdentity = [];
    foreach (rows($target, 'v2_server_route') as $row) {
        $routeByIdentity[identity($row, $routeIdentityFields)] = (int) $row['id'];
    }
    $routeMap = [];
    foreach (rows($source, 'v2_server_route') as $row) {
        $key = identity($row, $routeIdentityFields);
        if (!isset($routeByIdentity[$key])) {
            $routeByIdentity[$key] = insertRow($target, 'v2_server_route', $row, $targetColumns['v2_server_route']);
            $inserted['routes']++;
        }
        $routeMap[(int) $row['id']] = $routeByIdentity[$key];
    }

    $machineMap = [];
    $machineLookup = $target->prepare('SELECT id FROM v2_server_machine WHERE token = :token LIMIT 1');
    foreach (rows($source, 'v2_server_machine') as $row) {
        $machineLookup->execute([':token' => $row['token']]);
        $targetId = $machineLookup->fetchColumn();
        if ($targetId === false) {
            $targetId = insertRow($target, 'v2_server_machine', $row, $targetColumns['v2_server_machine']);
            $inserted['machines']++;
        }
        $machineMap[(int) $row['id']] = (int) $targetId;
    }

    $serverIdentityFields = array_values(array_intersect(
        ['type', 'name', 'host', 'port', 'server_port', 'protocol_settings', 'machine_id'],
        $sourceColumns['v2_server'],
        $targetColumns['v2_server']
    ));
    $serverByIdentity = [];
    foreach (rows($target, 'v2_server') as $row) {
        $serverByIdentity[identity($row, $serverIdentityFields)] = (int) $row['id'];
    }

    $serverMap = [];
    $insertedServerIds = [];
    $codeLookup = $target->prepare(
        'SELECT id FROM v2_server WHERE type = :type AND code = :code LIMIT 1'
    );
    foreach (rows($source, 'v2_server') as $row) {
        if ($row['machine_id'] !== null) {
            $sourceMachineId = (int) $row['machine_id'];
            if (!isset($machineMap[$sourceMachineId])) {
                fail('server references an unmapped machine ID');
            }
            $row['machine_id'] = $machineMap[$sourceMachineId];
        }
        $row['group_ids'] = remapJsonIds($row['group_ids'], $groupMap, 'group_ids');
        $row['route_ids'] = remapJsonIds($row['route_ids'], $routeMap, 'route_ids');

        $key = identity($row, $serverIdentityFields);
        if (isset($serverByIdentity[$key])) {
            $serverMap[(int) $row['id']] = $serverByIdentity[$key];
            continue;
        }

        if ($row['code'] !== null) {
            $codeLookup->execute([':type' => $row['type'], ':code' => $row['code']]);
            if ($codeLookup->fetchColumn() !== false) {
                // Preserve the recovered server configuration while avoiding a
                // legacy type/code collision with a distinct current record.
                $row['code'] = null;
            }
        }
        $sourceParentId = $row['parent_id'];
        $row['parent_id'] = null;
        $targetId = insertRow($target, 'v2_server', $row, $targetColumns['v2_server']);
        $inserted['servers']++;
        $serverMap[(int) $row['id']] = $targetId;
        $insertedServerIds[(int) $row['id']] = [
            'target_id' => $targetId,
            'source_parent_id' => $sourceParentId,
        ];
        $serverByIdentity[$key] = $targetId;
    }

    $parentUpdate = $target->prepare('UPDATE v2_server SET parent_id = :parent_id WHERE id = :id');
    foreach ($insertedServerIds as $relationship) {
        if ($relationship['source_parent_id'] === null) {
            continue;
        }
        $sourceParentId = (int) $relationship['source_parent_id'];
        if (!isset($serverMap[$sourceParentId])) {
            fail('server references an unmapped parent ID');
        }
        $parentUpdate->execute([
            ':parent_id' => $serverMap[$sourceParentId],
            ':id' => $relationship['target_id'],
        ]);
    }

    $target->commit();
    quickCheck($target);
    $foreignKeyErrors = $target->query('PRAGMA foreign_key_check')->fetchAll();
    if ($foreignKeyErrors !== []) {
        fail('foreign_key_check reported errors after the merge');
    }

    $after = [
        'servers' => tableCount($target, 'v2_server'),
        'machines' => tableCount($target, 'v2_server_machine'),
        'groups' => tableCount($target, 'v2_server_group'),
        'routes' => tableCount($target, 'v2_server_route'),
    ];
    echo json_encode([
        'status' => 'ok',
        'operation' => 'merge-server-configuration',
        'before' => $before,
        'inserted' => $inserted,
        'after' => $after,
        'quick_check' => 'ok',
        'foreign_key_check' => 'ok',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $exception) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    fail($exception->getMessage());
}
