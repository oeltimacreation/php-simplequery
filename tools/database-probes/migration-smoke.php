<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$targetName = $argv[1] ?? 'sqlite';
$target = ProbeTarget::named($targetName);
$driver = match ($target->engine) {
    'mysql' => Driver::MySql,
    'mariadb' => Driver::MariaDb,
    'sqlite' => Driver::Sqlite,
    default => throw new RuntimeException('Unsupported migration-smoke engine.'),
};
$output = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    }
}

$observations = [];
$runtime = [];
$connection = null;
$tablesCreated = false;
$record = static function (string $name, bool $condition, array $details = []) use (&$observations): void {
    if (!$condition) {
        throw new RuntimeException(sprintf('Migration smoke assertion failed: %s.', $name));
    }
    $observations[] = ['name' => $name, 'status' => 'passed', 'details' => $details];
};

try {
    $emulate = $driver === Driver::Sqlite
        ? null
        : (bool) ($target->options[PDO::ATTR_EMULATE_PREPARES] ?? false);
    $buffered = $driver === Driver::Sqlite
        ? null
        : (bool) ($target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true);
    $observer = new RecordingQueryObserver(100);
    $connection = Connection::connect(
        $driver,
        $target->dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(
            emulatePrepares: $emulate,
            bufferedQueries: $buffered,
            foundRows: $driver === Driver::Sqlite ? null : false,
            persistent: false,
            sqliteBusyTimeoutMilliseconds: $driver === Driver::Sqlite ? 5000 : null,
            label: 'migration-' . $targetName,
        ),
        $observer,
    );

    $versionSql = $driver === Driver::Sqlite
        ? 'SELECT sqlite_version() AS version'
        : 'SELECT VERSION() AS version';
    $versionRow = $connection->query($versionSql)->firstAssociative();
    $runtime['server_version'] = is_array($versionRow) ? ($versionRow['version'] ?? null) : null;

    foreach (['events', 'members', 'roles', 'teams'] as $suffix) {
        $connection->query('DROP TABLE IF EXISTS simplequery_migration_' . $suffix)->execute();
    }
    $idColumn = $driver === Driver::Sqlite
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $integerType = $driver === Driver::Sqlite ? 'INTEGER' : 'BIGINT';
    $textType = $driver === Driver::Sqlite ? 'TEXT' : 'VARCHAR(191)';
    $dateType = $driver === Driver::Sqlite ? 'TEXT' : 'DATETIME';
    $engineClause = $driver === Driver::Sqlite ? '' : ' ENGINE=InnoDB';
    $connection->query(
        sprintf(
            'CREATE TABLE simplequery_migration_teams (id %s, name %s NOT NULL)',
            $integerType . ' PRIMARY KEY',
            $textType,
        ) . $engineClause,
    )->execute();
    $connection->query(
        sprintf(
            'CREATE TABLE simplequery_migration_roles (id %s, name %s NOT NULL)',
            $integerType . ' PRIMARY KEY',
            $textType,
        ) . $engineClause,
    )->execute();
    $connection->query(
        sprintf(
            'CREATE TABLE simplequery_migration_members ('
            . 'id %s, team_id %s NOT NULL, role_id %s NULL, name %s NOT NULL, '
            . 'status %s NOT NULL, active %s NOT NULL)',
            $idColumn,
            $integerType,
            $integerType,
            $textType,
            $textType,
            $integerType,
        ) . $engineClause,
    )->execute();
    $connection->query(
        sprintf(
            'CREATE TABLE simplequery_migration_events ('
            . 'id %s, category %s NOT NULL, amount %s NOT NULL, created_at %s NOT NULL)',
            $idColumn,
            $textType,
            $driver === Driver::Sqlite ? 'NUMERIC' : 'DECIMAL(12, 2)',
            $dateType,
        ) . $engineClause,
    )->execute();
    $tablesCreated = true;

    $connection->table('simplequery_migration_teams')->insertMany([
        ['id' => 1, 'name' => 'Alpha'],
        ['id' => 2, 'name' => 'Beta'],
    ]);
    $connection->table('simplequery_migration_roles')->insertMany([
        ['id' => 1, 'name' => 'Admin'],
        ['id' => 2, 'name' => 'Reader'],
    ]);

    $generatedId = $connection->table(Identifier::of('simplequery_migration_members'))->insertGetId([
        'team_id' => 1,
        'role_id' => 1,
        'name' => 'Ada',
        'status' => 'ready',
        'active' => true,
    ]);
    $inserted = $connection->table('simplequery_migration_members')->where('id', (int) $generatedId)->first();
    if ($inserted !== null) {
        $inserted->displayName = 'Ada Synthetic';
    }
    $updated = $connection->table('simplequery_migration_members')->where('id', (int) $generatedId)->update([
        'status' => 'verified',
    ]);
    $record(
        'sqlite_style_crud_and_write_returns',
        ctype_digit($generatedId)
        && $inserted !== null
        && ($inserted->displayName ?? null) === 'Ada Synthetic'
        && $updated === 1,
        ['generated_id_type' => get_debug_type($generatedId)],
    );

    $connection->table('simplequery_migration_members')->insertMany([
        ['team_id' => 1, 'role_id' => 2, 'name' => 'Grace', 'status' => 'pending', 'active' => false],
        ['team_id' => 2, 'role_id' => null, 'name' => 'Linus', 'status' => 'ready', 'active' => true],
        ['team_id' => 2, 'role_id' => 2, 'name' => 'Margaret', 'status' => 'archived', 'active' => false],
    ]);
    $joined = $connection
        ->table('simplequery_migration_members', 'm')
        ->select('m.id', 'm.name', Identifier::of('t.name')->as('team_name'))
        ->join(Identifier::of('simplequery_migration_teams')->as('t'), 't.id', '=', 'm.team_id')
        ->where('m.active', true)
        ->orderBy('m.id')
        ->getAssociative();
    $record(
        'injected_model_join_shape',
        array_column($joined, 'name') === ['Ada', 'Linus']
        && array_column($joined, 'team_name') === ['Alpha', 'Beta'],
    );

    $connection->table('simplequery_migration_events')->insertMany([
        ['category' => 'paid', 'amount' => 10, 'created_at' => '2026-07-01 10:00:00'],
        ['category' => 'paid', 'amount' => 15, 'created_at' => '2026-07-02 10:00:00'],
        ['category' => 'void', 'amount' => 99, 'created_at' => '2026-06-01 10:00:00'],
    ]);
    $observer->clear();
    $rawReport = $connection->query(
        'SELECT category, SUM(amount) AS total FROM simplequery_migration_events '
        . 'WHERE category = ? GROUP BY category',
        ['paid'],
    );
    $beforeRawTerminal = count($observer->executions());
    $rawRow = $rawReport->firstAssociative();
    $afterRawTerminal = count($observer->executions());
    $record(
        'deferred_raw_reporting_terminal',
        $beforeRawTerminal === 0
        && $afterRawTerminal === 1
        && ($rawRow['category'] ?? null) === 'paid'
        && in_array($rawRow['total'] ?? null, [25, 25.0, '25', '25.00'], true),
    );

    $dateExpression = $driver === Driver::Sqlite
        ? "strftime('%Y-%m', created_at)"
        : "DATE_FORMAT(created_at, '%Y-%m')";
    $periods = $connection
        ->table('simplequery_migration_events')
        ->select(
            $connection->raw($dateExpression . ' AS period'),
            $connection->raw('COUNT(*) AS total'),
        )
        ->where('category', '!=', 'missing')
        ->groupBy($connection->raw($dateExpression))
        ->orderBy('period')
        ->getAssociative();
    $record(
        'vendor_date_expression_with_bound_filter',
        array_column($periods, 'period') === ['2026-06', '2026-07'],
        ['period_count' => count($periods)],
    );

    $pdo = $connection->pdo();
    $pdo->beginTransaction();
    $connection->table('simplequery_migration_members')->insert([
        'team_id' => 1,
        'role_id' => null,
        'name' => 'External',
        'status' => 'direct-pdo',
        'active' => true,
    ]);
    $activeBeforeExternalCommit = $pdo->inTransaction();
    $pdo->commit();
    $record(
        'direct_pdo_transaction_remains_caller_owned',
        $activeBeforeExternalCommit
        && $connection->table('simplequery_migration_members')->where('name', 'External')->count() === 1,
    );

    $observer->clear();
    $diagnosticQuery = $connection
        ->table('simplequery_migration_members')
        ->where('status', 'verified');
    $compiled = $diagnosticQuery->compile();
    $beforeDiagnosticExecution = count($observer->executions());
    $diagnosticQuery->getAssociative();
    $executions = $observer->executions();
    $record(
        'compile_and_redacted_observer_diagnostics',
        $beforeDiagnosticExecution === 0
        && count($executions) === 1
        && $executions[0]->sql === $compiled->sql
        && $executions[0]->parameterTypes === [ParameterType::String],
    );

    $listQuery = $connection
        ->table('simplequery_migration_members', 'm')
        ->select(
            'm.id',
            Identifier::of('m.name')->as('member_name'),
            Identifier::of('t.name')->as('team_name'),
            Identifier::of('r.name')->as('role_name'),
        )
        ->join(Identifier::of('simplequery_migration_teams')->as('t'), 't.id', '=', 'm.team_id')
        ->leftJoin(Identifier::of('simplequery_migration_roles')->as('r'), 'r.id', '=', 'm.role_id')
        ->where(static function (ConditionGroup $group): void {
            $group->where('m.active', true)->orWhere('m.status', 'pending');
        })
        ->whereIn('m.team_id', [1, 2])
        ->orderBy('m.id')
        ->limit(3);
    $nativeRows = $listQuery->getAssociative();
    $logicalCount = $listQuery->count();
    $direct = $pdo->prepare(
        'SELECT m.id, m.name AS member_name, t.name AS team_name, r.name AS role_name '
        . 'FROM simplequery_migration_members m '
        . 'INNER JOIN simplequery_migration_teams t ON t.id = m.team_id '
        . 'LEFT JOIN simplequery_migration_roles r ON r.id = m.role_id '
        . 'WHERE (m.active = ? OR m.status = ?) AND m.team_id IN (?, ?) ORDER BY m.id LIMIT 3',
    );
    if (!$direct instanceof PDOStatement) {
        throw new RuntimeException('The direct migration parity query could not be prepared.');
    }
    $direct->execute([1, 'pending', 1, 2]);
    $directRows = $direct->fetchAll(PDO::FETCH_ASSOC);
    $record(
        'complex_list_query_and_result_parity',
        $nativeRows === $directRows && $logicalCount === 4,
        ['page_rows' => count($nativeRows), 'logical_count' => $logicalCount],
    );

    $record(
        'native_api_without_runtime_pixie_layer',
        !class_exists('Pixie\\QueryBuilder\\QueryBuilderHandler', false),
    );
} catch (Throwable $throwable) {
    $observations[] = [
        'name' => 'migration_smoke',
        'status' => 'failed',
        'details' => ['exception' => $throwable::class, 'code' => (string) $throwable->getCode()],
    ];
} finally {
    if ($connection instanceof Connection) {
        if ($tablesCreated) {
            foreach (['events', 'members', 'roles', 'teams'] as $suffix) {
                try {
                    $connection->query('DROP TABLE IF EXISTS simplequery_migration_' . $suffix)->execute();
                } catch (Throwable) {
                }
            }
        }
        try {
            $connection->close();
        } catch (Throwable) {
        }
    }
}

$report = [
    'schema_version' => 1,
    'probe' => 'simplequery_migration',
    'target' => $targetName,
    'engine' => $target->engine,
    'runtime' => $runtime,
    'observations' => $observations,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, sprintf("Could not write migration-smoke report to %s.\n", $output));
    exit(2);
}

foreach ($observations as $observation) {
    if ($observation['status'] === 'failed') {
        exit(1);
    }
}
