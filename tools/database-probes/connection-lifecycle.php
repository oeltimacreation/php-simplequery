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
    fwrite(STDERR, "Usage: connection-lifecycle.php <mariadb|mysql> [--output=PATH]\n");
    exit(2);
}
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown lifecycle probe argument.');
    }
}

// Only use disposable fixtures: this probe expires and kills its own sessions.
$target = ProbeTarget::named($targetName);
$driver = $targetName === 'mariadb' ? Driver::MariaDb : Driver::MySql;
$observer = new RecordingQueryObserver(20);
$connect = static function () use ($target, $driver, $observer): Connection {
    $connection = Connection::connect(
        $driver,
        $target->dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(label: 'lifecycle-probe'),
        $observer,
    );
    $connection->query('SET SESSION wait_timeout = 30')->execute();

    return $connection;
};

$admin = $connect();
$report = new ProbeReport($targetName, $target->engine, [
    'php_version' => PHP_VERSION,
    'pdo_client_version' => $admin->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'server_version' => $admin->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'emulated_prepares' => $target->options[PDO::ATTR_EMULATE_PREPARES] ?? false,
    'buffered_queries' => $target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true,
]);
$require = static function (string $name, bool $condition) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Probe assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Lifecycle assertion failed: ' . $name);
    }
    $report->passed($name);
};
$sessionId = static function (Connection $connection): int {
    $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
    if ($row === null || (!is_int($row['id']) && !is_string($row['id']))) {
        throw new RuntimeException('Missing probe connection ID.');
    }

    return (int) $row['id'];
};
$connection = null;
$table = 'simplequery_lifecycle_' . bin2hex(random_bytes(6));
$created = false;

try {
    $admin->query('CREATE TABLE ' . $table . ' (marker INT PRIMARY KEY) ENGINE=InnoDB')->execute();
    $created = true;
    $connection = $connect();
    $require('fresh_local_state', !$connection->isClosed() && $connection->isReusable());

    // The application explicitly retires an idle handle before starting work.
    $oldId = $sessionId($connection);
    $lastUse = hrtime(true);
    usleep(20_000);
    $expired = (hrtime(true) - $lastUse) / 1_000_000_000 >= 0.01;
    $require('proactive_policy_boundary', $expired && $connection->isReusable());
    $connection->discard();
    $require('proactive_invalidation', $connection->isClosed());
    $connection = $connect();
    $require('proactive_new_session', $sessionId($connection) !== $oldId);

    for ($cycle = 1; $cycle <= 2; ++$cycle) {
        $old = $connection;
        $oldId = $sessionId($old);
        $old->query('SET SESSION wait_timeout = 1')->execute();
        $timeout = $old->query('SELECT @@SESSION.wait_timeout AS seconds')->firstAssociative();
        $require('idle_timeout_configured_' . $cycle, in_array($timeout['seconds'] ?? null, [1, '1'], true));
        $stale = $old->query('INSERT INTO ' . $table . ' (marker) VALUES (?)', [$cycle]);
        $observer->clear();
        sleep(2);

        // PDO local state is still clean, despite the server having expired it.
        $require('local_state_is_not_liveness_' . $cycle, $old->isReusable());
        $require('local_check_not_observed_as_query_' . $cycle, $observer->executions() === []);
        try {
            $stale->execute();
            throw new RuntimeException('Expired session unexpectedly executed a write.');
        } catch (QueryExecutionException $failure) {
            $report->passed('idle_failure_evidence_' . $cycle, [
                'sqlstate' => $failure->sqlState,
                'driver_code' => $failure->driverCode,
            ]);
            $require(
                'idle_loss_code_' . $cycle,
                in_array((string) $failure->driverCode, ['2006', '2013', '4031'], true),
            );
        }
        $events = $observer->executions();
        $require('single_failed_attempt_' . $cycle, count($events) === 1 && !$events[0]->successful);
        $old->discard();
        $old->discard();
        $old->close();
        $require('idle_terminal_invalidation_' . $cycle, $old->isClosed() && !$old->isReusable());
        $connection = $connect();
        $require('idle_replacement_session_' . $cycle, $sessionId($connection) !== $oldId);
        $require(
            'idle_write_not_replayed_' . $cycle,
            $connection->table($table)->where('marker', $cycle)->count() === 0,
        );
        try {
            $stale->execute();
            throw new RuntimeException('Stale query unexpectedly followed replacement.');
        } catch (ConnectionException) {
            $report->passed('stale_query_rejected_' . $cycle);
        }
        $require('next_unit_write_' . $cycle, $connection->table($table)->insert(['marker' => 10 + $cycle]) === 1);
    }

    // Abrupt loss is independent of the idle policy's threshold.
    $oldId = $sessionId($connection);
    $admin->query('KILL CONNECTION ' . $oldId)->execute();
    try {
        $connection->query('SELECT 1 AS value')->first();
        throw new RuntimeException('Killed session unexpectedly executed.');
    } catch (QueryExecutionException $failure) {
        $require('abrupt_loss_code', in_array((string) $failure->driverCode, ['2006', '2013'], true));
    }
    $connection->discard();
    $connection = null;

    // A failed application factory leaves its holder empty; no automatic retry.
    $attempts = 0;
    try {
        ++$attempts;
        $connection = Connection::connect(
            $driver,
            $target->dsn,
            'simplequery_nonexistent_probe_user',
            'synthetic-invalid-password',
            $target->options,
        );
        throw new RuntimeException('Invalid probe credentials unexpectedly connected.');
    } catch (ConnectionException) {
        $report->passed('replacement_construction_failure', ['factory_attempts' => $attempts]);
    }
    $connection = $connect();
    $require('recovery_after_failed_factory', $sessionId($connection) !== $oldId && $connection->isReusable());
    $require('acknowledged_writes_preserved', $connection->table($table)->count() === 2);
} catch (Throwable $failure) {
    // Avoid raw driver messages, DSNs, and exception traces in shared reports.
    $report->failed('lifecycle_probe', ['exception_class' => $failure::class]);
} finally {
    $connection?->discard();
    if ($created) {
        try {
            $admin->query('DROP TABLE ' . $table)->execute();
        } catch (Throwable $failure) {
            $report->failed('probe_table_cleanup', ['exception_class' => $failure::class]);
        }
    }
    $admin->discard();
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Could not write lifecycle probe report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
