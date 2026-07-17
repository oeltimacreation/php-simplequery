<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$targetName = $argv[1] ?? 'sqlite';
$target = ProbeTarget::named($targetName);
$driver = match ($target->engine) {
    'mysql' => Driver::MySql,
    'mariadb' => Driver::MariaDb,
    'sqlite' => Driver::Sqlite,
    default => throw new RuntimeException('Unsupported transaction-smoke engine.'),
};
$output = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    }
}

$sqlitePath = null;
$dsn = $target->dsn;
if ($driver === Driver::Sqlite) {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'simplequery-transaction-');
    if (!is_string($temporaryPath)) {
        throw new RuntimeException('Could not allocate the transaction-smoke SQLite file.');
    }
    $sqlitePath = $temporaryPath;
    $dsn = 'sqlite:' . $sqlitePath;
}

$emulate = $driver === Driver::Sqlite
    ? null
    : (bool) ($target->options[PDO::ATTR_EMULATE_PREPARES] ?? false);
$buffered = $driver === Driver::Sqlite
    ? null
    : (bool) ($target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true);
$connectionCounter = 0;
$connect = static function (?RecordingQueryObserver $observer = null) use (
    &$connectionCounter,
    $buffered,
    $driver,
    $dsn,
    $emulate,
    $target,
    $targetName,
): Connection {
    ++$connectionCounter;

    return Connection::connect(
        $driver,
        $dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(
            emulatePrepares: $emulate,
            bufferedQueries: $buffered,
            foundRows: $driver === Driver::Sqlite ? null : false,
            persistent: false,
            sqliteBusyTimeoutMilliseconds: $driver === Driver::Sqlite ? 5000 : null,
            label: sprintf('transaction-%s-%d', $targetName, $connectionCounter),
        ),
        $observer,
    );
};

$observations = [];
$runtime = [];
$admin = null;
$tableCreated = false;
$ddlTableCreated = false;
$record = static function (string $name, bool $condition, array $details = []) use (&$observations): void {
    if (!$condition) {
        throw new RuntimeException(sprintf('Transaction smoke assertion failed: %s.', $name));
    }
    $observations[] = ['name' => $name, 'status' => 'passed', 'details' => $details];
};
$isQuarantined = static function (Connection $connection): bool {
    try {
        $connection->query('SELECT 1')->firstAssociative();
    } catch (TransactionStateException $exception) {
        return $exception->connectionUnusable;
    }

    return false;
};

try {
    $admin = $connect();
    $versionSql = $driver === Driver::Sqlite
        ? 'SELECT sqlite_version() AS version'
        : 'SELECT VERSION() AS version';
    $versionRow = $admin->query($versionSql)->firstAssociative();
    $runtime['server_version'] = is_array($versionRow) ? ($versionRow['version'] ?? null) : null;

    $admin->query('DROP TABLE IF EXISTS simplequery_transaction_probe')->execute();
    $admin->query('DROP TABLE IF EXISTS simplequery_transaction_ddl_probe')->execute();
    $createSql = $driver === Driver::Sqlite
        ? 'CREATE TABLE simplequery_transaction_probe ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL UNIQUE)'
        : 'CREATE TABLE simplequery_transaction_probe ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(191) NOT NULL UNIQUE) ENGINE=InnoDB';
    $admin->query($createSql)->execute();
    $tableCreated = true;

    $returnedValue = new stdClass();
    $result = $admin->transaction(function (Connection $database) use ($returnedValue): stdClass {
        $database->table('simplequery_transaction_probe')->insert(['label' => 'outer-commit']);

        return $returnedValue;
    });
    $record(
        'outer_commit_and_callback_value',
        $result === $returnedValue
        && $admin->table('simplequery_transaction_probe')->where('label', 'outer-commit')->count() === 1
        && !$admin->pdo()->inTransaction(),
    );

    $rollbackFailure = new RuntimeException('controlled outer callback failure');
    $caught = null;
    try {
        $admin->transaction(function (Connection $database) use ($rollbackFailure): void {
            $database->table('simplequery_transaction_probe')->insert(['label' => 'outer-rollback']);
            throw $rollbackFailure;
        });
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }
    $record(
        'outer_callback_failure_identity_and_rollback',
        $caught === $rollbackFailure
        && $admin->table('simplequery_transaction_probe')->where('label', 'outer-rollback')->count() === 0,
    );

    $typeFailure = new TypeError('controlled callback type failure');
    $caught = null;
    try {
        $admin->transaction(function (Connection $database) use ($typeFailure): void {
            $database->table('simplequery_transaction_probe')->insert(['label' => 'type-rollback']);
            throw $typeFailure;
        });
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }
    $record(
        'callback_error_identity_and_rollback',
        $caught === $typeFailure
        && $admin->table('simplequery_transaction_probe')->where('label', 'type-rollback')->count() === 0,
    );

    $observer = new RecordingQueryObserver(20);
    $nestedConnection = $connect($observer);
    $nestedValue = new stdClass();
    $observer->clear();
    $nestedResult = $nestedConnection->transaction(
        function (Connection $database) use ($nestedValue): stdClass {
            $database->table('simplequery_transaction_probe')->insert(['label' => 'nested-outer-before']);
            $value = $database->transaction(function (Connection $nested) use ($nestedValue): stdClass {
                $nested->table('simplequery_transaction_probe')->insert(['label' => 'nested-inner']);

                return $nestedValue;
            });
            $database->table('simplequery_transaction_probe')->insert(['label' => 'nested-outer-after']);

            return $value;
        },
    );
    $depths = array_map(
        static fn (QueryExecution $execution): int => $execution->transactionDepth,
        $observer->executions(),
    );
    $record(
        'nested_success_and_control_observer_isolation',
        $nestedResult === $nestedValue && $depths === [1, 2, 1],
        ['query_depths' => $depths, 'observed_query_count' => count($observer->executions())],
    );
    $nestedConnection->close();

    $innerFailure = new RuntimeException('controlled inner callback failure');
    $innerIdentity = null;
    $admin->transaction(function (Connection $database) use ($innerFailure, &$innerIdentity): void {
        $database->table('simplequery_transaction_probe')->insert(['label' => 'inner-outer-before']);
        try {
            $database->transaction(function (Connection $nested) use ($innerFailure): void {
                $nested->table('simplequery_transaction_probe')->insert(['label' => 'inner-rolled-back']);
                throw $innerFailure;
            });
        } catch (Throwable $throwable) {
            $innerIdentity = $throwable;
        }
        $database->table('simplequery_transaction_probe')->insert(['label' => 'inner-outer-after']);
    });
    $record(
        'inner_rollback_with_outer_continuation',
        $innerIdentity === $innerFailure
        && $admin->table('simplequery_transaction_probe')->where('label', 'inner-rolled-back')->count() === 0
        && $admin->table('simplequery_transaction_probe')->where('label', 'inner-outer-after')->count() === 1,
    );

    $outerFailure = new RuntimeException('controlled outer rollback after nested success');
    $caught = null;
    try {
        $admin->transaction(function (Connection $database) use ($outerFailure): void {
            $database->table('simplequery_transaction_probe')->insert(['label' => 'outer-after-inner']);
            $database->transaction(function (Connection $nested): void {
                $nested->table('simplequery_transaction_probe')->insert(['label' => 'inner-before-outer-rollback']);
            });
            throw $outerFailure;
        });
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }
    $record(
        'outer_rollback_after_inner_success',
        $caught === $outerFailure
        && $admin->table('simplequery_transaction_probe')->where('label', 'outer-after-inner')->count() === 0
        && $admin->table('simplequery_transaction_probe')
            ->where('label', 'inner-before-outer-rollback')
            ->count() === 0,
    );

    $adminPdo = $admin->pdo();
    $adminPdo->beginTransaction();
    $admin->table('simplequery_transaction_probe')->insert(['label' => 'external-visible']);
    $externalCallbackCalled = false;
    $externalFailure = null;
    try {
        $admin->transaction(function () use (&$externalCallbackCalled): void {
            $externalCallbackCalled = true;
        });
    } catch (Throwable $throwable) {
        $externalFailure = $throwable;
    }
    $externalWasActive = $adminPdo->inTransaction();
    $externalRowVisible = $admin->table('simplequery_transaction_probe')->where('label', 'external-visible')->count();
    $adminPdo->rollBack();
    $record(
        'external_transaction_rejected_without_completion',
        $externalFailure instanceof ExternalTransactionException
        && !$externalCallbackCalled
        && $externalWasActive
        && $externalRowVisible === 1
        && $admin->table('simplequery_transaction_probe')->where('label', 'external-visible')->count() === 0,
    );

    $manualConnection = $connect();
    $manualPdo = $manualConnection->pdo();
    $manualFailure = null;
    try {
        $manualConnection->transaction(function (Connection $database) use ($manualPdo): void {
            $database->table('simplequery_transaction_probe')->insert(['label' => 'manual-commit']);
            $manualPdo->commit();
        });
    } catch (Throwable $throwable) {
        $manualFailure = $throwable;
    }
    $manualQuarantined = $isQuarantined($manualConnection);
    $manualConnection->close();
    $replacement = $connect();
    $replacementWorks = $replacement->table('simplequery_transaction_probe')->where('label', 'manual-commit')->count();
    $replacement->close();
    $record(
        'manual_completion_detection_and_connection_replacement',
        $manualFailure instanceof TransactionStateException
        && $manualFailure->connectionUnusable
        && $manualQuarantined
        && $replacementWorks === 1,
        ['operation' => $manualFailure instanceof TransactionException ? $manualFailure->operation : null],
    );

    $ddlConnection = $connect();
    $ddlFailure = null;
    try {
        $ddlConnection->transaction(function (Connection $database): void {
            $database->query(
                'CREATE TABLE simplequery_transaction_ddl_probe (id INTEGER NOT NULL)',
            )->execute();
        });
        $ddlTableCreated = true;
    } catch (Throwable $throwable) {
        $ddlFailure = $throwable;
        $ddlTableCreated = true;
    }
    $ddlStateLoss = $ddlFailure instanceof TransactionStateException;
    $ddlQuarantined = $ddlStateLoss && $isQuarantined($ddlConnection);
    $ddlConnection->close();
    $record(
        'ddl_transaction_state_characterized',
        $driver === Driver::Sqlite
            ? $ddlFailure === null
            : $ddlStateLoss && $ddlQuarantined,
        [
            'state_loss_detected' => $ddlStateLoss,
            'operation' => $ddlFailure instanceof TransactionException ? $ddlFailure->operation : null,
        ],
    );
    $admin->query('DROP TABLE IF EXISTS simplequery_transaction_ddl_probe')->execute();
    $ddlTableCreated = false;

    $commitCursorConnection = $connect();
    $commitCursorPdo = $commitCursorConnection->pdo();
    $commitCursor = null;
    $commitCursorFailure = null;
    try {
        $commitCursorConnection->transaction(function (Connection $database) use (&$commitCursor): void {
            $commitCursor = $database->table('simplequery_transaction_probe')->iterateAssociative();
        });
    } catch (Throwable $throwable) {
        $commitCursorFailure = $throwable;
    }
    if (!$commitCursor instanceof Cursor) {
        throw new RuntimeException('The outer-commit cursor scenario did not create a cursor.');
    }
    $commitCursorWasOpen = !$commitCursor->isClosed();
    $commitCursorQuarantined = $isQuarantined($commitCursorConnection);
    $commitCursor->close();
    if ($commitCursorPdo->inTransaction()) {
        $commitCursorPdo->rollBack();
    }
    $commitCursorConnection->close();
    $record(
        'live_cursor_blocks_outer_commit_without_forced_close',
        $commitCursorFailure instanceof TransactionStateException
        && $commitCursorWasOpen
        && $commitCursorQuarantined,
    );

    $rollbackCursorConnection = $connect();
    $rollbackCursorPdo = $rollbackCursorConnection->pdo();
    $rollbackCursor = null;
    $rollbackCursorCallbackFailure = new RuntimeException('cursor rollback callback failure');
    $rollbackCursorFailure = null;
    try {
        $rollbackCursorConnection->transaction(
            function (Connection $database) use (&$rollbackCursor, $rollbackCursorCallbackFailure): void {
                $rollbackCursor = $database->table('simplequery_transaction_probe')->iterateAssociative();
                throw $rollbackCursorCallbackFailure;
            },
        );
    } catch (Throwable $throwable) {
        $rollbackCursorFailure = $throwable;
    }
    if (!$rollbackCursor instanceof Cursor) {
        throw new RuntimeException('The outer-rollback cursor scenario did not create a cursor.');
    }
    $rollbackCursorWasOpen = !$rollbackCursor->isClosed();
    $rollbackCursor->close();
    if ($rollbackCursorPdo->inTransaction()) {
        $rollbackCursorPdo->rollBack();
    }
    $rollbackCursorConnection->close();
    $record(
        'live_cursor_blocks_outer_rollback_and_retains_failures',
        $rollbackCursorFailure instanceof TransactionException
        && $rollbackCursorFailure->callbackFailure === $rollbackCursorCallbackFailure
        && $rollbackCursorFailure->controlFailure instanceof TransactionStateException
        && $rollbackCursorFailure->connectionUnusable
        && $rollbackCursorWasOpen,
    );

    $releaseCursorConnection = $connect();
    $releaseCursorPdo = $releaseCursorConnection->pdo();
    $releaseCursor = null;
    $releaseCursorFailure = null;
    try {
        $releaseCursorConnection->transaction(function (Connection $database) use (&$releaseCursor): void {
            $database->transaction(function (Connection $nested) use (&$releaseCursor): void {
                $releaseCursor = $nested->table('simplequery_transaction_probe')->iterateAssociative();
            });
        });
    } catch (Throwable $throwable) {
        $releaseCursorFailure = $throwable;
    }
    if (!$releaseCursor instanceof Cursor) {
        throw new RuntimeException('The savepoint-release cursor scenario did not create a cursor.');
    }
    $releaseCursorWasOpen = !$releaseCursor->isClosed();
    $releaseCursor->close();
    if ($releaseCursorPdo->inTransaction()) {
        $releaseCursorPdo->rollBack();
    }
    $releaseCursorConnection->close();
    $record(
        'live_cursor_blocks_savepoint_release',
        $releaseCursorFailure instanceof TransactionStateException
        && $releaseCursorFailure->operation === 'release_savepoint'
        && $releaseCursorWasOpen,
    );

    $savepointRollbackConnection = $connect();
    $savepointRollbackPdo = $savepointRollbackConnection->pdo();
    $savepointRollbackCursor = null;
    $savepointCallbackFailure = new RuntimeException('savepoint cursor rollback failure');
    $savepointRollbackFailure = null;
    try {
        $savepointRollbackConnection->transaction(
            function (Connection $database) use (&$savepointRollbackCursor, $savepointCallbackFailure): void {
                $database->transaction(
                    function (Connection $nested) use (
                        &$savepointRollbackCursor,
                        $savepointCallbackFailure,
                    ): void {
                        $savepointRollbackCursor = $nested
                            ->table('simplequery_transaction_probe')
                            ->iterateAssociative();
                        throw $savepointCallbackFailure;
                    },
                );
            },
        );
    } catch (Throwable $throwable) {
        $savepointRollbackFailure = $throwable;
    }
    if (!$savepointRollbackCursor instanceof Cursor) {
        throw new RuntimeException('The savepoint-rollback cursor scenario did not create a cursor.');
    }
    $savepointRollbackWasOpen = !$savepointRollbackCursor->isClosed();
    $savepointRollbackCursor->close();
    if ($savepointRollbackPdo->inTransaction()) {
        $savepointRollbackPdo->rollBack();
    }
    $savepointRollbackConnection->close();
    $record(
        'live_cursor_blocks_savepoint_rollback_and_retains_failures',
        $savepointRollbackFailure instanceof TransactionException
        && $savepointRollbackFailure->operation === 'rollback_savepoint'
        && $savepointRollbackFailure->callbackFailure === $savepointCallbackFailure
        && $savepointRollbackWasOpen,
    );

    $lifecycleConnection = $connect();
    $lifecyclePdo = $lifecycleConnection->pdo();
    $lifecyclePdo->beginTransaction();
    $closeTransactionFailure = null;
    try {
        $lifecycleConnection->close();
    } catch (Throwable $throwable) {
        $closeTransactionFailure = $throwable;
    }
    $transactionStillActive = $lifecyclePdo->inTransaction();
    $lifecyclePdo->rollBack();
    $lifecycleCursor = $lifecycleConnection
        ->table('simplequery_transaction_probe')
        ->iterateAssociative();
    $closeCursorFailure = null;
    try {
        $lifecycleConnection->close();
    } catch (Throwable $throwable) {
        $closeCursorFailure = $throwable;
    }
    $lifecycleCursorWasOpen = !$lifecycleCursor->isClosed();
    $lifecycleCursor->close();
    $lifecycleConnection->close();
    $lifecycleConnection->close();
    $record(
        'connection_lifecycle_rejects_active_state_and_closes_idempotently',
        $closeTransactionFailure instanceof TransactionStateException
        && $transactionStillActive
        && $closeCursorFailure instanceof TransactionStateException
        && $lifecycleCursorWasOpen,
    );

    $record(
        'failure_injection_matrix_available',
        is_file(dirname(__DIR__, 2) . '/tests/Unit/ControlledTransactionPdo.php')
        && is_file(dirname(__DIR__, 2) . '/tests/Unit/TransactionFailureTest.php'),
        ['scope' => 'controlled PDO unit matrix; live engines exercise successful control paths'],
    );
} catch (Throwable $throwable) {
    $observations[] = [
        'name' => 'transaction_smoke',
        'status' => 'failed',
        'details' => ['exception' => $throwable::class, 'code' => (string) $throwable->getCode()],
    ];
} finally {
    if ($admin instanceof Connection) {
        if ($ddlTableCreated) {
            try {
                $admin->query('DROP TABLE IF EXISTS simplequery_transaction_ddl_probe')->execute();
            } catch (Throwable) {
            }
        }
        if ($tableCreated) {
            try {
                $admin->query('DROP TABLE IF EXISTS simplequery_transaction_probe')->execute();
            } catch (Throwable) {
            }
        }
        try {
            $admin->close();
        } catch (Throwable) {
        }
    }
    if (is_string($sqlitePath)) {
        foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

$report = [
    'schema_version' => 1,
    'probe' => 'simplequery_transaction',
    'target' => $targetName,
    'engine' => $target->engine,
    'runtime' => $runtime,
    'observations' => $observations,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, sprintf("Could not write transaction-smoke report to %s.\n", $output));
    exit(2);
}

foreach ($observations as $observation) {
    if ($observation['status'] === 'failed') {
        exit(1);
    }
}
