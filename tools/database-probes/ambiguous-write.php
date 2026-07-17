<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc < 2 || !in_array($argv[1], ['mariadb', 'mysql', 'proxysql', 'maxscale'], true)) {
    fwrite(STDERR, "Usage: ambiguous-write.php <target> [--reconcile=MARKER]\n");
    exit(2);
}

$target = ProbeTarget::named($argv[1]);
$reconcileMarker = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--reconcile=')) {
        $reconcileMarker = substr($argument, strlen('--reconcile='));
    }
}

$pdo = $target->connect();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sq_probe_ambiguity '
    . '(marker VARCHAR(64) PRIMARY KEY, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
);

if ($reconcileMarker !== null) {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM sq_probe_ambiguity WHERE marker = ?');
    $statement->execute([$reconcileMarker]);
    $count = $statement->fetchColumn();
    fwrite(
        STDOUT,
        json_encode(
            ['target' => $target->name, 'marker' => $reconcileMarker, 'stored_row_count' => $count],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . PHP_EOL,
    );
    exit(0);
}

$marker = bin2hex(random_bytes(16));
fwrite(
    STDOUT,
    sprintf(
        "marker=%s target=%s\nInterrupt transport/backend while the next statement is in flight, then press Enter.\n",
        $marker,
        $target->name,
    ),
);
fgets(STDIN);

$outcome = ['target' => $target->name, 'marker' => $marker, 'client_outcome' => 'success'];
try {
    $statement = $pdo->prepare(
        'INSERT INTO sq_probe_ambiguity (marker) '
        . 'SELECT ? FROM (SELECT SLEEP(0.5)) AS delayed_write',
    );
    $statement->execute([$marker]);
} catch (Throwable $throwable) {
    $outcome['client_outcome'] = 'error_or_unknown';
    $outcome['exception_class'] = $throwable::class;
    $outcome['code'] = (string) $throwable->getCode();
    if ($throwable instanceof PDOException && is_string($throwable->errorInfo[0] ?? null)) {
        $outcome['sqlstate'] = $throwable->errorInfo[0];
    }
}

$directTarget = in_array($target->name, ['proxysql', 'maxscale'], true) ? 'mariadb' : $target->name;
$outcome['reconciliation_command'] = sprintf(
    'php tools/database-probes/ambiguous-write.php %s --reconcile=%s',
    $directTarget,
    $marker,
);
fwrite(STDOUT, json_encode($outcome, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
