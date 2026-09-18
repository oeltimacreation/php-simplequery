<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeReport;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$targetName = $arguments[1] ?? '';
if (!in_array($targetName, ['mariadb', 'mysql'], true)) {
    fwrite(STDERR, "Usage: vendor-writes.php <mariadb|mysql> [--output=PATH]\n");
    exit(2);
}
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown vendor-writes probe argument.');
    }
}

// Only use disposable fixtures: this probe creates and drops a synthetic table.
$target = ProbeTarget::named($targetName);
$driver = $targetName === 'mariadb' ? Driver::MariaDb : Driver::MySql;
$table = 'simplequery_vendor_writes_' . bin2hex(random_bytes(6));
$connect = static function () use ($target, $driver): Connection {
    return Connection::connect(
        $driver,
        $target->dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(label: 'vendor-writes-probe'),
    );
};

$connection = $connect();
$locker = null;
$waiter = null;
$created = false;
$report = new ProbeReport($targetName, $target->engine, [
    'php_version' => PHP_VERSION,
    'pdo_client_version' => $connection->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'server_version' => $connection->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'emulated_prepares' => $target->options[PDO::ATTR_EMULATE_PREPARES] ?? false,
    'buffered_queries' => $target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true,
    'table' => $table,
]);

$require = static function (string $name, bool $condition, array $details = []) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Probe assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Vendor write assertion failed: ' . $name);
    }
    $report->passed($name, $details);
};
$sessionId = static function (Connection $connection): int {
    $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
    if ($row === null || (!is_int($row['id']) && !is_string($row['id']))) {
        throw new RuntimeException('Missing probe connection ID.');
    }

    return (int) $row['id'];
};

try {
    $connection->query(
        'CREATE TABLE ' . $table . ' ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'label VARCHAR(191) NOT NULL UNIQUE, '
        . 'state VARCHAR(32) NOT NULL, '
        . 'payload LONGTEXT NULL'
        . ') ENGINE=InnoDB',
    )->execute();
    $created = true;

    // Immediate generated IDs must be read on the same session as the write.
    $firstId = $connection->table($table)->insertGetId(['label' => 'first', 'state' => 'ready']);
    $secondId = $connection->table($table)->insertGetId(['label' => 'second', 'state' => 'ready']);
    $require(
        'insert_get_id_increments',
        ctype_digit($firstId) && ctype_digit($secondId) && (int) $secondId > (int) $firstId,
        ['first' => $firstId, 'second' => $secondId],
    );

    $sessionBefore = $sessionId($connection);
    $thirdId = $connection->table($table)->insertGetId(['label' => 'third', 'state' => 'ready']);
    $sessionAfter = $sessionId($connection);
    $require(
        'same_session_write_to_id_read',
        $sessionBefore === $sessionAfter
            && (int) $thirdId > (int) $secondId
            && $connection->isReusable(),
        ['session_id' => $sessionAfter, 'id' => $thirdId],
    );

    $existingId = $connection->table($table)->where('label', 'first')->firstAssociative()['id'] ?? null;
    try {
        $connection->table($table)->insert(['label' => 'first', 'state' => 'duplicate']);
        $report->failed('duplicate_insert_is_error', ['reason' => 'duplicate insert succeeded']);
    } catch (QueryExecutionException $failure) {
        $require(
            'duplicate_insert_is_error',
            (string) $failure->driverCode === '1062' && $failure->sqlState === '23000',
            ['driver_code' => $failure->driverCode],
        );
    }

    // Ignored duplicates and vendor upserts: record affected rows and the
    // last-insert-ID value instead of assuming one portable behavior.
    $ignored = $connection->query(
        'INSERT IGNORE INTO ' . $table . ' (label, state) VALUES (?, ?)',
        ['first', 'ignored'],
    )->execute();
    $ignoredLastId = $connection->pdo()->lastInsertId();
    $require('insert_ignore_duplicate_affected_zero', $ignored === 0, ['affected_rows' => $ignored]);

    $upsertExisting = $connection->query(
        'INSERT INTO ' . $table . ' (label, state) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE state = ?',
        ['first', 'upserted', 'upserted'],
    )->execute();
    $upsertExistingLastId = $connection->pdo()->lastInsertId();
    $require(
        'upsert_existing_row_affected_two',
        $upsertExisting === 2
            && ($connection->table($table)->where('label', 'first')->firstAssociative()['state'] ?? null)
                === 'upserted',
        ['affected_rows' => $upsertExisting],
    );

    $upsertNew = $connection->query(
        'INSERT INTO ' . $table . ' (label, state) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE state = ?',
        ['fourth', 'upserted-new', 'upserted-new'],
    )->execute();
    $upsertNewLastIdValue = $connection->pdo()->lastInsertId();
    $upsertNewLastId = is_numeric($upsertNewLastIdValue) ? (int) $upsertNewLastIdValue : 0;
    $require(
        'upsert_new_row_affected_one',
        $upsertNew === 1 && $upsertNewLastId > (int) $thirdId,
        ['affected_rows' => $upsertNew, 'last_insert_id' => $upsertNewLastId],
    );

    // The documented vendor idiom for reading the existing row's ID is an
    // explicit LAST_INSERT_ID(id) expression; the library cannot use it for a
    // generic insertGetId.
    $connection->query(
        'INSERT INTO ' . $table . ' (label, state) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), state = ?',
        ['first', 'touched', 'touched'],
    )->execute();
    $expressionLastIdValue = $connection->pdo()->lastInsertId();
    $expressionLastId = is_numeric($expressionLastIdValue) ? (int) $expressionLastIdValue : 0;
    $existingIdValue = is_numeric($existingId) ? (int) $existingId : 0;
    $require(
        'last_insert_id_expression_returns_existing_id',
        $existingIdValue > 0 && $expressionLastId === $existingIdValue,
        ['existing_id' => $existingIdValue, 'last_insert_id' => $expressionLastId],
    );

    $report->observed('duplicate_and_upsert_id_evidence', [
        'insert_ignore_last_insert_id' => $ignoredLastId,
        'upsert_existing_last_insert_id' => $upsertExistingLastId,
        'upsert_new_last_insert_id' => $upsertNewLastId,
        'last_insert_id_expression' => $expressionLastId,
    ]);

    $batchAffected = $connection->table($table)->insertMany([
        ['label' => 'batch-one', 'state' => 'ready'],
        ['label' => 'batch-two', 'state' => 'ready'],
    ]);
    $afterBatchId = $connection->table($table)->insertGetId(['label' => 'after-batch', 'state' => 'ready']);
    $require(
        'batch_affected_and_next_id',
        $batchAffected === 2 && (int) $afterBatchId > $upsertNewLastId,
        ['affected_rows' => $batchAffected, 'next_id' => $afterBatchId],
    );

    // Bind-count and payload limits are engine-specific; record the boundary
    // instead of advertising a portable ceiling.
    $withinLimitValues = range(1, 5000);
    $withinLimit = $connection->table($table)->whereIn('id', $withinLimitValues)->count();
    $require('bind_count_within_limit', $withinLimit >= 0, [
        'values' => count($withinLimitValues),
    ]);
    $overLimitOutcome = 'completed';
    $overLimitCode = null;
    try {
        $connection->table($table)->whereIn('id', range(1, 65536))->count();
    } catch (QueryExecutionException $failure) {
        $overLimitOutcome = 'failed';
        $overLimitCode = $failure->driverCode;
    }
    $report->observed('bind_count_limit', [
        'values_within' => count($withinLimitValues),
        'values_over' => 65536,
        'over_limit_outcome' => $overLimitOutcome,
        'over_limit_driver_code' => $overLimitCode,
    ]);

    $packetRow = $connection->query('SELECT @@max_allowed_packet AS bytes')->firstAssociative();
    $largePayload = str_repeat('simplequery-', 32768);
    $connection->table($table)->insert([
        'label' => 'payload',
        'state' => 'ready',
        'payload' => $largePayload,
    ]);
    $payloadRow = $connection->query(
        'SELECT CHAR_LENGTH(payload) AS length FROM ' . $table . ' WHERE label = ?',
        ['payload'],
    )->firstAssociative();
    $require(
        'payload_row_round_trip',
        ($payloadRow['length'] ?? null) === strlen($largePayload),
        ['max_allowed_packet' => $packetRow['bytes'] ?? null, 'length' => $payloadRow['length'] ?? null],
    );

    // Same-owner row locks: a second connection must not slip past a lock held
    // by the connection that owns the transaction.
    $locker = $connect();
    $waiter = $connect();
    $waiter->query('SET SESSION innodb_lock_wait_timeout = 1')->execute();
    $lockedRow = $connection->table($table)->where('label', 'second')->firstAssociative();
    $lockedId = $lockedRow['id'] ?? null;
    if (!is_int($lockedId) && !is_string($lockedId)) {
        throw new RuntimeException('Missing lock target row.');
    }
    $lockFailure = null;
    $locker->transaction(function (Connection $transaction) use ($table, $lockedId, $waiter, &$lockFailure): void {
        $transaction->table($table)->where('id', (int) $lockedId)->forUpdate()->firstAssociative();
        $waiter->pdo()->beginTransaction();
        try {
            $waiter->table($table)->where('id', (int) $lockedId)->forUpdate()->firstAssociative();
        } catch (QueryExecutionException $failure) {
            $lockFailure = $failure;
        } finally {
            if ($waiter->pdo()->inTransaction()) {
                $waiter->pdo()->rollBack();
            }
        }
        $transaction->table($table)->where('id', (int) $lockedId)->update(['state' => 'locked-committed']);
    });
    $require(
        'row_lock_blocks_other_owner',
        $lockFailure instanceof QueryExecutionException
            && (string) $lockFailure->driverCode === '1205',
        ['driver_code' => $lockFailure?->driverCode],
    );
    $require(
        'row_lock_release_visible',
        ($waiter->table($table)->where('id', (int) $lockedId)->firstAssociative()['state'] ?? null)
            === 'locked-committed',
    );
} catch (Throwable $failure) {
    // Avoid raw driver messages, DSNs, and exception traces in shared reports.
    $report->failed('vendor_writes_probe', ['exception_class' => $failure::class]);
} finally {
    $connection->discard();
    $locker?->discard();
    $waiter?->discard();
    if ($created) {
        try {
            $cleanup = $connect();
            $cleanup->query('DROP TABLE ' . $table)->execute();
            $cleanup->discard();
        } catch (Throwable $failure) {
            $report->failed('probe_table_cleanup', ['exception_class' => $failure::class]);
        }
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Could not write vendor-writes probe report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
