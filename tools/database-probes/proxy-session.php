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
if (!in_array($targetName, ['proxysql', 'maxscale'], true)) {
    fwrite(STDERR, "Usage: proxy-session.php <proxysql|maxscale> [--output=PATH]\n");
    exit(2);
}
$output = null;
foreach (array_slice($arguments, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        throw new InvalidArgumentException('Unknown proxy-session probe argument.');
    }
}

// Only use disposable fixtures: this probe creates and drops a synthetic table.
$target = ProbeTarget::named($targetName);
$driver = $target->engine === 'mysql' ? Driver::MySql : Driver::MariaDb;
$table = 'simplequery_proxy_session_' . bin2hex(random_bytes(6));
$connect = static function () use ($target, $driver): Connection {
    return Connection::connect(
        $driver,
        $target->dsn,
        $target->username,
        $target->password,
        $target->options,
        new ConnectionOptions(label: 'proxy-session-probe'),
    );
};

$connection = $connect();
$fresh = null;
$created = false;
$report = new ProbeReport($targetName, $target->engine, [
    'php_version' => PHP_VERSION,
    'pdo_client_version' => $connection->pdo()->getAttribute(PDO::ATTR_CLIENT_VERSION),
    'server_version' => $connection->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'emulated_prepares' => $target->options[PDO::ATTR_EMULATE_PREPARES] ?? false,
    'buffered_queries' => $target->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true,
    'table' => $table,
]);

$require = static function (string $name, bool $condition) use ($report): void {
    if ($name === '') {
        throw new InvalidArgumentException('Probe assertion name cannot be empty.');
    }
    if (!$condition) {
        throw new RuntimeException('Proxy session assertion failed: ' . $name);
    }
    $report->passed($name);
};
$sessionId = static function (Connection $connection): ?int {
    $row = $connection->query('SELECT CONNECTION_ID() AS id')->firstAssociative();
    $value = $row['id'] ?? null;
    if (!is_int($value) && !is_string($value)) {
        return null;
    }

    return (int) $value;
};

try {
    $connection->query('CREATE TABLE ' . $table . ' (id INT AUTO_INCREMENT PRIMARY KEY, marker VARCHAR(64) NOT NULL)')
        ->execute();
    $created = true;
    $require('connect_and_ddl', true);

    // A multiplexing proxy may legitimately route sequential statements to
    // different backend sessions; record the observed stability.
    $sessionIds = [$sessionId($connection), $sessionId($connection), $sessionId($connection)];
    $report->observed('session_identity_sequence', [
        'ids' => $sessionIds,
        'stable' => count(array_unique($sessionIds)) === 1,
    ]);

    $bound = $connection->query('SELECT ? AS value', ['proxy-bound-value'])->firstAssociative();
    $require('bound_values_correct', ($bound['value'] ?? null) === 'proxy-bound-value');
    $first = $connection->query('SELECT ? AS value', [1])->firstAssociative();
    $second = $connection->query('SELECT ? AS value', [2])->firstAssociative();
    $firstValue = $first['value'] ?? null;
    $secondValue = $second['value'] ?? null;
    $require(
        'prepared_statement_reuse',
        is_numeric($firstValue) && is_numeric($secondValue)
            && (int) $firstValue === 1
            && (int) $secondValue === 2,
    );

    // Committed work must be visible through a fresh connection, and rolled
    // back work must not be.
    $marker = 'commit-' . bin2hex(random_bytes(6));
    $connection->transaction(static function (Connection $transaction) use ($table, $marker): void {
        $transaction->table($table)->insert(['marker' => $marker]);
    });
    $fresh = $connect();
    $require(
        'transaction_commit_visible',
        $fresh->table($table)->where('marker', $marker)->count() === 1,
    );
    $rollbackMarker = 'rollback-' . bin2hex(random_bytes(6));
    try {
        $connection->transaction(static function (Connection $transaction) use ($table, $rollbackMarker): void {
            $transaction->table($table)->insert(['marker' => $rollbackMarker]);
            throw new RuntimeException('Synthetic proxy-session rollback.');
        });
    } catch (RuntimeException) {
        // Expected rollback.
    }
    $require(
        'transaction_rollback_hidden',
        $fresh->table($table)->where('marker', $rollbackMarker)->count() === 0,
    );

    // Session-variable and user-variable handling is proxy-specific: record
    // what the proxy forwards instead of assuming backend session behavior.
    $waitTimeoutBefore = $connection->query('SELECT @@SESSION.wait_timeout AS value')->firstAssociative();
    $setOutcome = 'set';
    try {
        $connection->query('SET SESSION wait_timeout = 1')->execute();
    } catch (QueryExecutionException $failure) {
        $setOutcome = (string) $failure->driverCode;
    }
    $waitTimeoutAfter = $connection->query('SELECT @@SESSION.wait_timeout AS value')->firstAssociative();
    $report->observed('session_variable_round_trip', [
        'set_outcome' => $setOutcome,
        'before' => $waitTimeoutBefore['value'] ?? null,
        'after' => $waitTimeoutAfter['value'] ?? null,
    ]);

    $userVariableOutcome = 'unset';
    try {
        $connection->query('SET @simplequery_probe = 42')->execute();
        $userVariable = $connection->query('SELECT @simplequery_probe AS value')->firstAssociative();
        $userVariableOutcome = $userVariable['value'] ?? null;
    } catch (QueryExecutionException $failure) {
        $userVariableOutcome = 'error:' . (string) $failure->driverCode;
    }
    $report->observed('user_variable_round_trip', ['value' => $userVariableOutcome]);

    $idleOutcome = 'not_tested';
    $waitTimeoutValue = $waitTimeoutAfter['value'] ?? null;
    if (is_numeric($waitTimeoutValue) && (int) $waitTimeoutValue === 1) {
        sleep(2);
        try {
            $connection->query('SELECT 1 AS value')->firstAssociative();
            $idleOutcome = 'success';
        } catch (QueryExecutionException $failure) {
            $idleOutcome = (string) $failure->driverCode;
        }
    }
    $report->observed('idle_after_session_timeout', ['outcome' => $idleOutcome]);

    // Application-level recovery must work through the proxy regardless of
    // which session policy the proxy applied above.
    $connection->discard();
    $connection = $connect();
    $require('recovery_after_proxy_session', $connection->query('SELECT 1 AS value')->firstAssociative() !== null);

    // Cursor streaming through the proxy preserves order and row count.
    $firstMarker = 'cursor-' . bin2hex(random_bytes(6));
    $connection->table($table)->insertMany([
        ['marker' => $firstMarker . '-a'],
        ['marker' => $firstMarker . '-b'],
        ['marker' => $firstMarker . '-c'],
    ]);
    $cursor = $connection
        ->table($table)
        ->where('marker', 'like', $firstMarker . '%')
        ->orderBy('id')
        ->iterateAssociative();
    $streamed = [];
    try {
        foreach ($cursor as $row) {
            $streamed[] = $row['marker'];
        }
    } finally {
        $cursor->close();
    }
    $require(
        'cursor_stream_order',
        $streamed === [$firstMarker . '-a', $firstMarker . '-b', $firstMarker . '-c'],
    );
} catch (Throwable $failure) {
    // Avoid raw driver messages, DSNs, and exception traces in shared reports.
    $report->failed('proxy_session_probe', ['exception_class' => $failure::class]);
} finally {
    $connection->discard();
    $fresh?->discard();
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
    fwrite(STDERR, "Could not write proxy-session probe report.\n");
    exit(2);
}
exit($report->hasFailures() ? 1 : 0);
