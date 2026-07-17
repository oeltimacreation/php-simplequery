<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 2) {
    fwrite(STDERR, "Usage: wait.php <probe-target>\n");
    exit(2);
}

$target = ProbeTarget::named($arguments[1]);
$deadline = microtime(true) + 45.0;
$lastFailure = 'no connection attempt';

do {
    try {
        $pdo = $target->connect();
        $statement = $pdo->query('SELECT 1');
        if ($statement !== false && $statement->fetchColumn() !== false) {
            printf("%s is ready.\n", $target->name);
            exit(0);
        }
    } catch (Throwable $throwable) {
        $lastFailure = sprintf('%s (code %s)', $throwable::class, (string) $throwable->getCode());
    }

    usleep(250_000);
} while (microtime(true) < $deadline);

fwrite(STDERR, sprintf("%s did not become ready: %s\n", $target->name, $lastFailure));
exit(1);
