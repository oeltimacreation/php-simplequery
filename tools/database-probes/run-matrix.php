<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\PdoBehaviorProbe;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\ProbeTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$targetNames = ['sqlite', 'mariadb', 'mysql', 'proxysql', 'maxscale'];
$reports = [];
$failed = false;

foreach ($targetNames as $targetName) {
    try {
        $target = ProbeTarget::named($targetName);
        $report = (new PdoBehaviorProbe($target, $target->connect()))->run();
        $reports[] = $report;
        $failed = $failed || $report->hasFailures();
    } catch (Throwable $throwable) {
        $reports[] = [
            'schema_version' => 1,
            'target' => $targetName,
            'status' => 'connection_failed',
            'exception_class' => $throwable::class,
            'exception_code' => (string) $throwable->getCode(),
        ];
        $failed = true;
    }
}

fwrite(
    STDOUT,
    json_encode(['schema_version' => 1, 'reports' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
);

exit($failed ? 1 : 0);
