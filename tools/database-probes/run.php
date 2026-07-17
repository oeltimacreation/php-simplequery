<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\PdoBehaviorProbe;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$targetName = $argv[1] ?? 'sqlite';
$target = ProbeTarget::named($targetName);

try {
    $report = (new PdoBehaviorProbe($target, $target->connect()))->run();
} catch (Throwable $throwable) {
    fwrite(
        STDERR,
        sprintf(
            "Could not connect to %s: %s (code %s)\n",
            $targetName,
            $throwable::class,
            (string) $throwable->getCode(),
        ),
    );
    exit(1);
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
$output = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    }
}

if ($output === null) {
    fwrite(STDOUT, $json);
} elseif (file_put_contents($output, $json) === false) {
    fwrite(STDERR, sprintf("Could not write probe report to %s.\n", $output));
    exit(2);
}

exit($report->hasFailures() ? 1 : 0);
