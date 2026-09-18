<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\CountingPdo;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeReport;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
// Explicit support-file loading keeps the probe runnable against a baseline
// checkout whose autoloader has runtime classes only.
require __DIR__ . '/src/ProbeReport.php';
require __DIR__ . '/src/ProbeTarget.php';
require __DIR__ . '/src/CountingPdo.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$targetName = $arguments[1] ?? '';
if (!in_array($targetName, ['mariadb', 'mysql', 'proxysql', 'maxscale'], true)) {
    fwrite(STDERR, "Usage: transaction-cost.php <mariadb|mysql|proxysql|maxscale> [--output=PATH]\n");
    exit(2);
}
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown transaction-cost probe argument.');
    }
}

// Only use disposable fixtures: this probe creates and drops a synthetic table.
$target = ProbeTarget::named($targetName);
$driver = $target->engine === 'mysql' ? Driver::MySql : Driver::MariaDb;
$isMariaDb = $target->engine !== 'mysql';
$table = 'simplequery_tx_cost_' . bin2hex(random_bytes(6));
$sessionSql = $isMariaDb
    ? 'SET SESSION max_statement_time = 0.5'
    : 'SET SESSION max_execution_time = 500';

$pdo = new CountingPdo($target->dsn, $target->username, $target->password, $target->options);
$connectionOptions = static fn (): ConnectionOptions => new ConnectionOptions(
    emulatePrepares: (bool) ($target->options[PDO::ATTR_EMULATE_PREPARES] ?? false),
    bufferedQueries: (bool) ($target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true),
    foundRows: false,
    label: 'transaction-cost-probe',
);
$connection = Connection::fromPdo($pdo, $driver, $connectionOptions());
$created = false;
$report = new ProbeReport($targetName, $target->engine, [
    'php_version' => PHP_VERSION,
    'pdo_client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'emulated_prepares' => $target->options[PDO::ATTR_EMULATE_PREPARES] ?? false,
    'buffered_queries' => $target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true,
    'table' => $table,
]);

$require = static function (string $name, bool $condition, array $details = []) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Probe assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Transaction cost assertion failed: ' . $name);
    }
    $report->passed($name, $details);
};

/**
 * @param Closure(int): array<string, mixed> $work
 * @return array{samples: list<float>, facts: array<string, mixed>}
 */
$runLoop = static function (int $iterations, int $warmups, Closure $work): array {
    for ($index = 0; $index < $warmups; ++$index) {
        $work($index);
    }
    $samples = [];
    $facts = [];
    for ($index = 0; $index < $iterations; ++$index) {
        $started = hrtime(true);
        $facts = $work($index);
        $samples[] = (hrtime(true) - $started) / 1_000;
    }
    sort($samples);

    return ['samples' => $samples, 'facts' => $facts];
};

/** @param list<float> $samples */
$percentile = static function (array $samples, float $quantile): float {
    $index = (int) floor($quantile * (count($samples) - 1));

    return $samples[$index];
};

/** @param array{samples: list<float>, facts: array<string, mixed>} $measurement */
$timing = static function (array $measurement) use ($percentile): array {
    $samples = $measurement['samples'];
    $count = count($samples);

    return [
        'samples' => $count,
        'minimum_us' => round($samples[0], 1),
        'median_us' => round($percentile($samples, 0.5), 1),
        'p95_us' => round($percentile($samples, 0.95), 1),
        'maximum_us' => round($samples[$count - 1], 1),
    ];
};

$iterations = 200;
$warmups = 20;
$cleanTable = static function () use ($connection, $table): void {
    $connection->table($table)->delete();
};

try {
    $pdo->exec(
        'CREATE TABLE ' . $table . ' ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'marker VARCHAR(32) NOT NULL'
        . ') ENGINE=InnoDB',
    );
    $created = true;

    // Raw-PDO controls isolate driver cost from library controls.
    $pdo->startCounting();
    $pdoRoot = $runLoop($iterations, $warmups, function (int $index) use ($pdo, $cleanTable, $table): array {
        $cleanTable();
        $pdo->beginTransaction();
        $statement = $pdo->prepare('INSERT INTO ' . $table . ' (marker) VALUES (?)');
        $statement->execute(['pdo-root-' . $index]);
        $pdo->commit();

        return ['rows' => 1];
    });
    $pdo->stopCounting();
    $pdoRootCounts = $pdo->controlCounts;
    $pdoRootInspections = $pdo->inspectionCalls;
    $require(
        'pdo_root_control_sequence',
        $pdoRoot['facts'] === ['rows' => 1]
            && ($pdoRootCounts['begin'] ?? 0) === $iterations + $warmups
            && ($pdoRootCounts['commit'] ?? 0) === $iterations + $warmups
            && ($pdoRootCounts['savepoint'] ?? 0) === 0,
    );

    $pdo->startCounting();
    $pdoNoop = $runLoop($iterations, $warmups, static function () use ($pdo): array {
        $pdo->beginTransaction();
        $pdo->commit();

        return ['rows' => 0];
    });
    $pdo->stopCounting();
    $pdoNoopCounts = $pdo->controlCounts;
    $pdoNoopInspections = $pdo->inspectionCalls;
    $require(
        'pdo_noop_control_sequence',
        $pdoNoop['facts'] === ['rows' => 0]
            && ($pdoNoopCounts['begin'] ?? 0) === $iterations + $warmups
            && ($pdoNoopCounts['commit'] ?? 0) === $iterations + $warmups
            && ($pdoNoopCounts['savepoint'] ?? 0) === 0,
    );

    $pdo->startCounting();
    $pdoSavepoint = $runLoop($iterations, $warmups, function (int $index) use ($pdo, $cleanTable, $table): array {
        $cleanTable();
        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT transaction_cost_probe');
        $pdo->exec('RELEASE SAVEPOINT transaction_cost_probe');
        $statement = $pdo->prepare('INSERT INTO ' . $table . ' (marker) VALUES (?)');
        $statement->execute(['pdo-savepoint-' . $index]);
        $pdo->commit();

        return ['rows' => 1];
    });
    $pdo->stopCounting();
    $pdoSavepointCounts = $pdo->controlCounts;
    $pdoSavepointInspections = $pdo->inspectionCalls;
    $require(
        'pdo_savepoint_control_sequence',
        $pdoSavepoint['facts'] === ['rows' => 1]
            && ($pdoSavepointCounts['savepoint'] ?? 0) === $iterations + $warmups
            && ($pdoSavepointCounts['release_savepoint'] ?? 0) === $iterations + $warmups,
    );

    // Managed operations. Counting covers warm-ups plus measured iterations.
    $pdo->startCounting();
    $managedRootNoop = $runLoop($iterations, $warmups, static function () use ($connection): array {
        return $connection->transaction(static fn (): array => ['ok' => true]);
    });
    $pdo->stopCounting();
    $managedRootNoopCounts = $pdo->controlCounts;
    $managedRootNoopInspections = $pdo->inspectionCalls;
    $require(
        'managed_root_noop_controls',
        ($managedRootNoopCounts['begin'] ?? 0) === $iterations + $warmups
            && ($managedRootNoopCounts['commit'] ?? 0) === $iterations + $warmups
            && ($managedRootNoopCounts['savepoint'] ?? 0) === $iterations + $warmups
            && ($managedRootNoopCounts['release_savepoint'] ?? 0) === $iterations + $warmups
            && ($managedRootNoopCounts['rollback_to_savepoint'] ?? 0) === 0,
    );

    $pdo->startCounting();
    $managedRootWrite = $runLoop($iterations, $warmups, function (int $index) use (
        $connection,
        $cleanTable,
        $table,
    ): array {
        $cleanTable();

        return $connection->transaction(
            static fn (): array => [
                'rows' => $connection->table($table)->insert(['marker' => 'managed-root-' . $index]),
            ],
        );
    });
    $pdo->stopCounting();
    $managedRootWriteCounts = $pdo->controlCounts;
    $managedRootWriteInspections = $pdo->inspectionCalls;
    $require(
        'managed_root_write_controls',
        $managedRootWrite['facts'] === ['rows' => 1]
            && ($managedRootWriteCounts['savepoint'] ?? 0) === $iterations + $warmups
            && ($managedRootWriteCounts['release_savepoint'] ?? 0) === $iterations + $warmups,
    );

    $pdo->startCounting();
    $managedNestedNoop = $runLoop($iterations, $warmups, static function () use ($connection): array {
        return $connection->transaction(
            static fn (): array => $connection->transaction(static fn (): array => ['ok' => true]),
        );
    });
    $pdo->stopCounting();
    $managedNestedNoopCounts = $pdo->controlCounts;
    $managedNestedNoopInspections = $pdo->inspectionCalls;
    $require(
        'managed_nested_noop_controls',
        ($managedNestedNoopCounts['savepoint'] ?? 0) === 2 * ($iterations + $warmups)
            && ($managedNestedNoopCounts['release_savepoint'] ?? 0) === 2 * ($iterations + $warmups),
    );

    $pdo->startCounting();
    $managedNestedWrite = $runLoop($iterations, $warmups, function (int $index) use (
        $connection,
        $cleanTable,
        $table,
    ): array {
        $cleanTable();

        return $connection->transaction(
            static fn (): array => $connection->transaction(
                static fn (): array => [
                    'rows' => $connection->table($table)->insert(['marker' => 'managed-nested-' . $index]),
                ],
            ),
        );
    });
    $pdo->stopCounting();
    $managedNestedWriteCounts = $pdo->controlCounts;
    $managedNestedWriteInspections = $pdo->inspectionCalls;
    $require(
        'managed_nested_write_controls',
        $managedNestedWrite['facts'] === ['rows' => 1]
            && ($managedNestedWriteCounts['savepoint'] ?? 0) === 2 * ($iterations + $warmups),
    );

    $pdo->startCounting();
    $managedNestedFailure = $runLoop($iterations, $warmups, static function () use ($connection): array {
        return $connection->transaction(static function () use ($connection): array {
            try {
                $connection->transaction(static function (): void {
                    throw new RuntimeException('Synthetic nested failure.');
                });
            } catch (RuntimeException) {
                // Expected nested rollback; the outer transaction continues.
            }

            return ['ok' => true];
        });
    });
    $pdo->stopCounting();
    $managedNestedFailureCounts = $pdo->controlCounts;
    $managedNestedFailureInspections = $pdo->inspectionCalls;
    $require(
        'managed_nested_failure_controls',
        ($managedNestedFailureCounts['rollback_to_savepoint'] ?? 0) === $iterations + $warmups
            && ($managedNestedFailureCounts['commit'] ?? 0) === $iterations + $warmups
            && ($managedNestedFailureCounts['rollback'] ?? 0) === 0,
    );

    $pdo->startCounting();
    $managedRollback = $runLoop($iterations, $warmups, function () use ($connection, $cleanTable): array {
        $cleanTable();
        $rolledBack = false;
        try {
            $connection->transaction(static function (): void {
                throw new RuntimeException('Synthetic outer failure.');
            });
        } catch (RuntimeException) {
            $rolledBack = true;
        }

        return ['rolled_back' => $rolledBack];
    });
    $pdo->stopCounting();
    $managedRollbackCounts = $pdo->controlCounts;
    $managedRollbackInspections = $pdo->inspectionCalls;
    $require(
        'managed_rollback_controls',
        $managedRollback['facts'] === ['rolled_back' => true]
            && ($managedRollbackCounts['rollback'] ?? 0) === $iterations + $warmups
            && ($managedRollbackCounts['commit'] ?? 0) === 0
            && ($managedRollbackCounts['release_savepoint'] ?? 0) === $iterations + $warmups,
    );
    $require('managed_rollback_leaves_no_rows', $connection->table($table)->count() === 0);

    // Connection acquisition and session initialization are separate costs.
    $acquisitionIterations = 50;
    $acquisitionWarmups = 5;
    $acquisition = $runLoop($acquisitionIterations, $acquisitionWarmups, static function () use (
        $target,
        $driver,
        $connectionOptions,
    ): array {
        $fresh = Connection::connect(
            $driver,
            $target->dsn,
            $target->username,
            $target->password,
            $target->options,
            $connectionOptions(),
        );
        $fresh->close();

        return ['acquired' => true];
    });
    $sessionInitialization = $runLoop($iterations, $warmups, static function () use ($connection, $sessionSql): array {
        $connection->query($sessionSql)->execute();

        return ['configured' => true];
    });
    $require('acquisition_and_session_initialization', $acquisition['facts'] === ['acquired' => true]
        && $sessionInitialization['facts'] === ['configured' => true]);

    $perIteration = static function (int $count, int $iterations, int $warmups): float {
        return round($count / ($iterations + $warmups), 3);
    };
    $rootGuardMedian = round($percentile($managedRootNoop['samples'], 0.5), 1);
    $rawNoopMedian = round($percentile($pdoNoop['samples'], 0.5), 1);
    $rootWriteMedian = round($percentile($managedRootWrite['samples'], 0.5), 1);
    $rawSavepointMedian = round($percentile($pdoSavepoint['samples'], 0.5), 1);

    $report->observed('control_counts', [
        'iterations' => $iterations,
        'warmups' => $warmups,
        'pdo_noop' => [
            'counts' => $pdoNoopCounts,
            'per_iteration' => [
                'begin' => $perIteration($pdoNoopCounts['begin'] ?? 0, $iterations, $warmups),
                'commit' => $perIteration($pdoNoopCounts['commit'] ?? 0, $iterations, $warmups),
            ],
            'inspections' => $pdoNoopInspections,
        ],
        'pdo_root' => [
            'counts' => $pdoRootCounts,
            'per_iteration' => [
                'begin' => $perIteration($pdoRootCounts['begin'] ?? 0, $iterations, $warmups),
                'commit' => $perIteration($pdoRootCounts['commit'] ?? 0, $iterations, $warmups),
            ],
            'inspections' => $pdoRootInspections,
        ],
        'pdo_savepoint' => [
            'counts' => $pdoSavepointCounts,
            'per_iteration' => [
                'savepoint' => $perIteration($pdoSavepointCounts['savepoint'] ?? 0, $iterations, $warmups),
                'release_savepoint' => $perIteration(
                    $pdoSavepointCounts['release_savepoint'] ?? 0,
                    $iterations,
                    $warmups,
                ),
            ],
            'inspections' => $pdoSavepointInspections,
        ],
        'managed_root_noop' => [
            'counts' => $managedRootNoopCounts,
            'inspections' => $managedRootNoopInspections,
        ],
        'managed_root_write' => [
            'counts' => $managedRootWriteCounts,
            'inspections' => $managedRootWriteInspections,
        ],
        'managed_nested_noop' => [
            'counts' => $managedNestedNoopCounts,
            'inspections' => $managedNestedNoopInspections,
        ],
        'managed_nested_write' => [
            'counts' => $managedNestedWriteCounts,
            'inspections' => $managedNestedWriteInspections,
        ],
        'managed_nested_failure' => [
            'counts' => $managedNestedFailureCounts,
            'inspections' => $managedNestedFailureInspections,
        ],
        'managed_rollback' => [
            'counts' => $managedRollbackCounts,
            'inspections' => $managedRollbackInspections,
        ],
    ]);

    $report->observed('timing_microseconds', [
        'pdo_control_noop' => $timing($pdoNoop),
        'pdo_control_root' => $timing($pdoRoot),
        'pdo_control_savepoint' => $timing($pdoSavepoint),
        'managed_root_noop' => $timing($managedRootNoop),
        'managed_root_write' => $timing($managedRootWrite),
        'managed_nested_noop' => $timing($managedNestedNoop),
        'managed_nested_write' => $timing($managedNestedWrite),
        'managed_nested_failure' => $timing($managedNestedFailure),
        'managed_rollback' => $timing($managedRollback),
        'connection_acquisition' => $timing($acquisition),
        'session_initialization' => $timing($sessionInitialization),
    ]);

    $report->observed('d5_attribution', [
        'root_guard_and_inspection_over_raw_noop_us' => round($rootGuardMedian - $rawNoopMedian, 1),
        'root_write_over_raw_savepoint_pair_us' => round($rootWriteMedian - $rawSavepointMedian, 1),
        'nested_pair_over_root_us' => round(
            $percentile($managedNestedNoop['samples'], 0.5) - $rootGuardMedian,
            1,
        ),
        'session_initialization_median_us' => round($percentile($sessionInitialization['samples'], 0.5), 1),
        'connection_acquisition_median_us' => round($percentile($acquisition['samples'], 0.5), 1),
    ]);
} catch (Throwable $failure) {
    // Assertion names are synthetic and safe; driver messages and traces are not retained.
    $report->failed('transaction_cost_probe', [
        'exception_class' => $failure::class,
        'message' => $failure->getMessage(),
    ]);
} finally {
    if ($created) {
        try {
            $pdo->exec('DROP TABLE ' . $table);
        } catch (Throwable $failure) {
            $report->failed('probe_table_cleanup', ['exception_class' => $failure::class]);
        }
    }
    $pdo->stopCounting();
    try {
        $connection->close();
    } catch (Throwable) {
        // Diagnostics keep an unusable connection for the failed assertion.
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Could not write transaction-cost probe report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
