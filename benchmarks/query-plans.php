<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\QueryPlanEvidence;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

fwrite(
    STDOUT,
    json_encode(QueryPlanEvidence::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . PHP_EOL,
);
