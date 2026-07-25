<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$targetName = $arguments[1] ?? 'sqlite';
$target = ProbeTarget::named($targetName);
$driver = match ($target->engine) {
    'mysql' => Driver::MySql,
    'mariadb' => Driver::MariaDb,
    'sqlite' => Driver::Sqlite,
    default => throw new RuntimeException('Unsupported execution-smoke engine.'),
};
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    }
}

$observations = [];
$runtime = [];
$connection = null;
$tableCreated = false;

$record = static function (string $name, bool $condition, array $details = []) use (&$observations): void {
    if (!$condition) {
        throw new RuntimeException(sprintf('Execution smoke assertion failed: %s.', $name));
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
            label: 'phase2-' . $targetName,
        ),
        $observer,
    );

    $versionSql = $driver === Driver::Sqlite
        ? 'SELECT sqlite_version() AS version'
        : 'SELECT VERSION() AS version';
    $versionRow = $connection->query($versionSql)->firstAssociative();
    $runtime['server_version'] = is_array($versionRow) ? ($versionRow['version'] ?? null) : null;

    $connection->query('DROP TABLE IF EXISTS simplequery_phase2_probe')->execute();
    $createSql = $driver === Driver::Sqlite
        ? 'CREATE TABLE simplequery_phase2_probe ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL, '
            . 'decimal_value NUMERIC NULL, binary_value BLOB NULL, lob_value BLOB NULL)'
        : 'CREATE TABLE simplequery_phase2_probe ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(191) NOT NULL UNIQUE, enabled TINYINT NOT NULL, '
            . 'decimal_value DECIMAL(30, 10) NULL, binary_value VARBINARY(255) NULL, lob_value LONGBLOB NULL) '
            . 'ENGINE=InnoDB';
    $connection->query($createSql)->execute();
    $tableCreated = true;

    $lob = fopen('php://temp', 'w+b');
    if (!is_resource($lob)) {
        throw new RuntimeException('Could not create the execution-smoke LOB stream.');
    }
    fwrite($lob, 'phase2-lob');
    rewind($lob);
    $generatedId = $connection->table('simplequery_phase2_probe')->insertGetId([
        'label' => 'phase2-one',
        'enabled' => true,
        'decimal_value' => 10.25,
        'binary_value' => new Binding("phase2\0binary", ParameterType::Binary),
        'lob_value' => new Binding($lob, ParameterType::Lob),
    ]);
    fclose($lob);
    $record('generated_id_string', $generatedId !== '0' && ctype_digit($generatedId), ['type' => 'string']);

    $batchAffected = $connection->table('simplequery_phase2_probe')->insertMany([
        [
            'label' => 'phase2-two',
            'enabled' => true,
            'decimal_value' => '20.5000000000',
            'binary_value' => null,
            'lob_value' => null,
        ],
        [
            'label' => 'phase2-three',
            'enabled' => false,
            'decimal_value' => null,
            'binary_value' => null,
            'lob_value' => null,
        ],
    ]);
    $record('genuine_multi_row_insert', $batchAffected === 2, ['affected_rows' => $batchAffected]);

    $objects = $connection->table('simplequery_phase2_probe')->orderBy('id')->get();
    $associative = $connection->table('simplequery_phase2_probe')->orderBy('id')->getAssociative();
    $record(
        'object_and_associative_hydration',
        count($objects) === 3
        && count($associative) === 3
        && ($objects[0]->label ?? null) === 'phase2-one'
        && ($associative[1]['label'] ?? null) === 'phase2-two',
    );

    $mixedIn = $connection
        ->table('simplequery_phase2_probe')
        ->select('label')
        ->whereIn('decimal_value', [null, '20.5000000000'])
        ->orderBy('id')
        ->getAssociative();
    $mixedNotIn = $connection
        ->table('simplequery_phase2_probe')
        ->whereNotIn('decimal_value', [null, '20.5000000000'])
        ->getAssociative();
    $nullOnlyIn = $connection
        ->table('simplequery_phase2_probe')
        ->whereIn('decimal_value', [null])
        ->getAssociative();
    $nullOnlyNotIn = $connection
        ->table('simplequery_phase2_probe')
        ->whereNotIn('decimal_value', [null])
        ->getAssociative();
    $record(
        'null_containing_in_three_valued_logic',
        array_column($mixedIn, 'label') === ['phase2-two']
        && $mixedNotIn === []
        && $nullOnlyIn === []
        && $nullOnlyNotIn === [],
    );

    $oversizedInteger = '18446744073709551616000000000000000001';
    $oversizedRow = $connection->query('SELECT ? AS oversized_value', [$oversizedInteger])->firstAssociative();
    $record(
        'oversized_integer_string_round_trip',
        ($oversizedRow['oversized_value'] ?? null) === $oversizedInteger,
    );

    $duplicateObject = $connection->query(
        'SELECT 1 AS duplicate_name, 2 AS duplicate_name',
    )->first();
    $duplicateAssociative = $connection->query(
        'SELECT 1 AS duplicate_name, 2 AS duplicate_name',
    )->firstAssociative();
    $record(
        'duplicate_result_column_last_value',
        ($duplicateObject->duplicate_name ?? null) === 2
        && $duplicateAssociative === ['duplicate_name' => 2],
    );

    $numericAlias = $driver === Driver::Sqlite ? '"0"' : '`0`';
    $numericObject = $connection->query('SELECT 3 AS ' . $numericAlias)->first();
    $numericAssociativeRejected = false;
    try {
        $connection->query('SELECT 3 AS ' . $numericAlias)->firstAssociative();
    } catch (QueryExecutionException) {
        $numericAssociativeRejected = true;
    }
    $record(
        'numeric_result_column_policy',
        ($numericObject->{'0'} ?? null) === 3 && $numericAssociativeRejected,
    );

    $count = $connection->table('simplequery_phase2_probe')->orderBy('id')->limit(1)->count();
    $sum = $connection->table('simplequery_phase2_probe')->sum('decimal_value');
    $record(
        'aggregate_execution_without_select_row_count',
        $count === 3 && (is_int($sum) || is_float($sum) || is_string($sum)),
        ['count' => $count, 'sum_type' => get_debug_type($sum)],
    );

    $changed = $connection->table('simplequery_phase2_probe')->where('label', 'phase2-two')->update([
        'enabled' => false,
    ]);
    $unchanged = $connection->table('simplequery_phase2_probe')->where('label', 'phase2-two')->update([
        'enabled' => false,
    ]);
    $record(
        'changed_row_affected_semantics',
        $changed === 1 && ($driver === Driver::Sqlite || $unchanged === 0),
        ['changed' => $changed, 'unchanged' => $unchanged],
    );

    $raw = $connection->query(
        'SELECT label FROM simplequery_phase2_probe WHERE id = ?',
        [(int) $generatedId],
    )->firstAssociative();
    $record('raw_prepared_terminal', ($raw['label'] ?? null) === 'phase2-one');

    $cursor = $connection->table('simplequery_phase2_probe')->orderBy('id')->iterateAssociative();
    foreach ($cursor as $row) {
        $record('cursor_first_row', ($row['label'] ?? null) === 'phase2-one');
        break;
    }
    $cursor->close();
    $record(
        'safe_early_cursor_close',
        $cursor->isClosed() && $connection->table('simplequery_phase2_probe')->count() === 3,
    );

    if ($driver !== Driver::Sqlite) {
        $connection->query('SET @simplequery_phase2_session = ?', ['phase2-session'])->execute();
        $session = $connection->query('SELECT @simplequery_phase2_session AS session_value')->firstAssociative();
        $record('proxy_session_continuity', ($session['session_value'] ?? null) === 'phase2-session');

        try {
            $connection->table('simplequery_phase2_probe')->where('id', (int) $generatedId)->forUpdate()->first();
            throw new RuntimeException('A row lock unexpectedly executed outside a transaction.');
        } catch (\Oeltima\SimpleQuery\Exception\TransactionStateException) {
        }
        $connection->pdo()->beginTransaction();
        $locked = $connection
            ->table('simplequery_phase2_probe')
            ->where('id', (int) $generatedId)
            ->forUpdate()
            ->first();
        $connection->pdo()->rollBack();
        $record('row_lock_transaction_requirement', ($locked->label ?? null) === 'phase2-one');
    }

    try {
        $connection->table('simplequery_phase2_probe')->insert([
            'label' => 'phase2-one',
            'enabled' => true,
            'decimal_value' => null,
            'binary_value' => null,
            'lob_value' => null,
        ]);
        throw new RuntimeException('Duplicate insert unexpectedly succeeded.');
    } catch (QueryExecutionException $exception) {
        $record(
            'redacted_exception_conversion',
            $exception->sqlState !== null
            && $exception->getPrevious() instanceof PDOException
            && !str_contains($exception->getMessage(), 'phase2-one'),
            ['sql_state' => $exception->sqlState, 'driver_code' => $exception->driverCode],
        );
    }

    $executions = $observer->executions();
    $record(
        'bounded_observer_diagnostics',
        count($executions) >= 10
        && $executions[count($executions) - 1]->successful === false
        && $executions[count($executions) - 1]->connectionLabel === 'phase2-' . $targetName,
        ['statement_attempts' => count($executions)],
    );

    $deleted = $connection->table('simplequery_phase2_probe')->delete();
    $record('affected_row_delete', $deleted === 3, ['affected_rows' => $deleted]);
} catch (Throwable $throwable) {
    $observations[] = [
        'name' => 'execution_smoke',
        'status' => 'failed',
        'details' => ['exception' => $throwable::class, 'code' => (string) $throwable->getCode()],
    ];
} finally {
    if ($connection instanceof Connection) {
        if ($tableCreated) {
            try {
                $connection->query('DROP TABLE IF EXISTS simplequery_phase2_probe')->execute();
            } catch (Throwable) {
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
    'probe' => 'simplequery_phase2_execution',
    'target' => $targetName,
    'engine' => $target->engine,
    'runtime' => $runtime,
    'observations' => $observations,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, sprintf("Could not write execution-smoke report to %s.\n", $output));
    exit(2);
}

foreach ($observations as $observation) {
    if ($observation['status'] === 'failed') {
        exit(1);
    }
}
