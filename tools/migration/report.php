<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Migration\MigrationCorpusReporter;
use Oeltima\SimpleQuery\Tools\Migration\SourcePatternRewriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fixture = dirname(__DIR__, 2) . '/tests/Fixtures/Migration/representative-slices.json';
$report = (new MigrationCorpusReporter(new SourcePatternRewriter()))->report($fixture);

fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

exit(($report['status'] ?? null) === 'passed' ? 0 : 1);
