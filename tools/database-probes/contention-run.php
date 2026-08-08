<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\DatabaseProbe\SqliteContentionProbe;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$report = (new SqliteContentionProbe())->run();
fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
