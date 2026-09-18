<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeReport;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$targetName = $arguments[1] ?? '';
if (!in_array($targetName, ['mariadb', 'mysql'], true)) {
    fwrite(STDERR, "Usage: session-hygiene.php <mariadb|mysql> [--output=PATH]\n");
    exit(2);
}
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown session-hygiene probe argument.');
    }
}

// Only use disposable fixtures: this probe changes its own session settings.
$target = ProbeTarget::named($targetName);
$isMariaDb = $targetName === 'mariadb';
$driver = $isMariaDb ? Driver::MariaDb : Driver::MySql;
$limitVariable = $isMariaDb ? 'max_statement_time' : 'max_execution_time';
$timeoutCode = $isMariaDb ? '1969' : '3024';
$initializationSql = $isMariaDb
    ? 'SET SESSION max_statement_time = 0.1'
    : 'SET SESSION max_execution_time = 100';
$reportSql = $isMariaDb
    ? 'SET SESSION max_statement_time = 5'
    : 'SET SESSION max_execution_time = 5000';
$initializationValue = $isMariaDb ? 0.1 : 100.0;
$reportValue = $isMariaDb ? 5.0 : 5000.0;

$observer = new RecordingQueryObserver(64);
$connect = static function () use ($target, $driver, $observer, $initializationSql): Connection {
    $connection = Connection::connect(
        $driver,
        $target->dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(label: 'session-hygiene-probe'),
        $observer,
    );
    $connection->query($initializationSql)->execute();

    return $connection;
};

$table = 'simplequery_session_hygiene_' . bin2hex(random_bytes(6));
$first = $connect();
$second = null;
$created = false;
$report = new ProbeReport($targetName, $target->engine, [
    'php_version' => PHP_VERSION,
    'pdo_client_version' => $first->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'server_version' => $first->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'emulated_prepares' => $target->options[PDO::ATTR_EMULATE_PREPARES] ?? false,
    'buffered_queries' => $target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true,
    'statement_limit_variable' => $limitVariable,
    'statement_limit_unit' => $isMariaDb ? 'seconds' : 'milliseconds',
    'initialization_value' => $initializationValue,
    'report_value' => $reportValue,
    'statement_limit_code' => $timeoutCode,
]);

$require = static function (string $name, bool $condition) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Probe assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Session hygiene assertion failed: ' . $name);
    }
    $report->passed($name);
};
$readLimit = static function (Connection $connection) use ($limitVariable): float {
    $row = $connection->query('SELECT @@SESSION.' . $limitVariable . ' AS value')->firstAssociative();
    $value = $row['value'] ?? null;
    if (!is_numeric($value)) {
        throw new RuntimeException('Session statement limit is not numeric.');
    }

    return (float) $value;
};
$sessionId = static function (Connection $connection): int {
    $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
    if ($row === null || (!is_int($row['id']) && !is_string($row['id']))) {
        throw new RuntimeException('Missing probe connection ID.');
    }

    return (int) $row['id'];
};
$expectTimeout = static function (Connection $connection, string $sql, string $code): QueryExecutionException {
    try {
        $connection->query($sql)->first();
    } catch (QueryExecutionException $failure) {
        if ((string) $failure->driverCode !== $code) {
            throw new RuntimeException('Unexpected statement-limit evidence.');
        }

        return $failure;
    }

    throw new RuntimeException('A limited statement unexpectedly completed.');
};

try {
    $first->query(
        'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY) ENGINE=InnoDB',
    )->execute();
    $created = true;
    $first->query('INSERT INTO ' . $table . ' (id) VALUES (1), (2), (3)')->execute();
    $second = $connect();
    $require('initialization_applied', abs($readLimit($first) - $initializationValue) < 0.000001);
    $require('second_session_initialized', abs($readLimit($second) - $initializationValue) < 0.000001);

    // The statement limit is session-scoped, so a report session must not leak
    // its raised value into an ordinary session.
    $first->query($reportSql)->execute();
    $require('report_limit_raised', abs($readLimit($first) - $reportValue) < 0.000001);
    $require('session_scope_isolated', abs($readLimit($second) - $initializationValue) < 0.000001);
    $first->query($initializationSql)->execute();
    $require('temporary_limit_restored', abs($readLimit($first) - $initializationValue) < 0.000001);

    // The statement limit is distinct from the connect, idle, and lock
    // timeouts that the guide documents.
    $limits = $first->query(
        'SELECT @@SESSION.wait_timeout AS wait_timeout,'
        . ' @@GLOBAL.wait_timeout AS global_wait_timeout,'
        . ' @@SESSION.innodb_lock_wait_timeout AS lock_wait_timeout',
    )->firstAssociative();
    if ($limits === null) {
        throw new RuntimeException('Missing session timeout inputs.');
    }
    $report->observed('timeout_inputs', $limits);

    // A read-only SELECT is bounded by the statement limit, and the session
    // remains usable afterwards.
    $longSelect = 'SELECT COUNT(*) AS rows_seen FROM ' . $table . ' WHERE SLEEP(0.6) = 0';
    $failure = $expectTimeout($first, $longSelect, $timeoutCode);
    $report->passed('select_limit_enforced', [
        'sqlstate' => $failure->sqlState,
        'driver_code' => $failure->driverCode,
    ]);
    $require(
        'session_survives_statement_limit',
        abs($readLimit($first) - $initializationValue) < 0.000001
            && $first->query('SELECT 1 AS value')->firstAssociative() !== null,
    );

    // The limit scope differs by engine: MariaDB documents statement scope,
    // MySQL documents read-only SELECT scope. Either way, a statement limit is
    // not a portable write or commit deadline.
    if ($isMariaDb) {
        $failure = $expectTimeout(
            $first,
            'UPDATE ' . $table . ' SET id = id + 100 WHERE SLEEP(0.6) = 0',
            $timeoutCode,
        );
        $report->passed('dml_statement_bounded', ['driver_code' => $failure->driverCode]);
    } else {
        $started = hrtime(true);
        $first->query('DO SLEEP(0.6)')->execute();
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
        $require('non_select_not_bounded', $elapsedMilliseconds >= 500);
        $report->observed('non_select_elapsed_ms', ['value' => round($elapsedMilliseconds, 1)]);
    }

    // Restoration is functional: the report runs under the raised limit, and
    // the initialized limit applies again afterwards.
    $first->query($reportSql)->execute();
    $first->query('SELECT COUNT(*) AS rows_seen FROM ' . $table . ' WHERE SLEEP(0.3) = 0')
        ->firstAssociative();
    $first->query($initializationSql)->execute();
    $restoredSelect = 'SELECT COUNT(*) AS rows_seen FROM ' . $table . ' WHERE SLEEP(0.3) = 0';
    $failure = $expectTimeout($first, $restoredSelect, $timeoutCode);
    $report->passed('restored_limit_applies', ['driver_code' => $failure->driverCode]);

    // A failed restoration retires the session; a replacement is a new session
    // that must be initialized again before it is published.
    $oldId = $sessionId($first);
    $first->discard();
    try {
        $first->query($initializationSql)->execute();
        $report->failed('restore_failure_detected', ['reason' => 'closed wrapper executed SQL']);
    } catch (ConnectionException $restoreFailure) {
        $report->passed('restore_failure_detected', ['operation' => $restoreFailure->operation]);
    }
    $first = $connect();
    $require('replacement_is_new_session', $sessionId($first) !== $oldId);
    $require('replacement_reinitialized', abs($readLimit($first) - $initializationValue) < 0.000001);

    // Observation boundaries: local inspection, direct PDO session commands,
    // and transaction controls do not appear as query executions.
    $observer->clear();
    $first->isReusable();
    $require('local_inspection_not_observed', $observer->executions() === []);

    $observer->clear();
    $first->pdo()->exec($initializationSql);
    $require('direct_pdo_unobserved', $observer->executions() === []);

    $observer->clear();
    $first->query($initializationSql)->execute();
    $require('builder_session_command_observed', count($observer->executions()) === 1);

    $observer->clear();
    $first->transaction(static fn (): ?bool => null);
    $require('transaction_controls_unobserved', $observer->executions() === []);

    // Acquisition and initialization cost on the disposable fixture.
    $samples = [];
    for ($index = 0; $index < 5; ++$index) {
        $started = hrtime(true);
        $probeConnection = $connect();
        $samples[] = (hrtime(true) - $started) / 1_000;
        $probeConnection->discard();
    }
    sort($samples);
    $report->observed('acquisition_and_initialization_microseconds', [
        'samples' => count($samples),
        'minimum' => round($samples[0], 1),
        'median' => round($samples[intdiv(count($samples), 2)], 1),
        'maximum' => round($samples[count($samples) - 1], 1),
    ]);
} catch (Throwable $failure) {
    // Avoid raw driver messages, DSNs, and exception traces in shared reports.
    $report->failed('session_hygiene_probe', ['exception_class' => $failure::class]);
} finally {
    $first->discard();
    $second?->discard();
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
    fwrite(STDERR, "Could not write session-hygiene probe report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
