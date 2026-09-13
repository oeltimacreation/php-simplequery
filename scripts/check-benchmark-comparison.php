<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\BenchmarkComparisonGate;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Usage: check-benchmark-comparison.php <baseline-first.json> <candidate-first.json>\n");
    exit(2);
}
$reports = [];
foreach ([$arguments[1], $arguments[2]] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, sprintf("Benchmark comparison report does not exist: %s\n", $path));
        exit(2);
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        fwrite(STDERR, sprintf("Benchmark comparison report cannot be read: %s\n", $path));
        exit(2);
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, sprintf("Benchmark comparison report is not valid JSON: %s\n", $path));
        exit(2);
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, sprintf("Benchmark comparison report is not an object: %s\n", $path));
        exit(2);
    }
    $reports[] = $decoded;
}
try {
    $result = (new BenchmarkComparisonGate())->evaluate($reports[0], $reports[1]);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}
if ($result['controls'] !== []) {
    fwrite(
        STDOUT,
        'Retained non-blocking benchmark control signals: ' . implode(', ', $result['controls']) . PHP_EOL,
    );
}
$oneOrder = array_values(array_diff($result['signals'], [...$result['blocking'], ...$result['controls']]));
if ($oneOrder !== []) {
    fwrite(STDOUT, 'Non-repeatable source-order benchmark signals retained in the comparison artifacts.' . PHP_EOL);
}
if ($result['blocking'] !== []) {
    fwrite(STDERR, 'Repeatable benchmark regressions: ' . implode(', ', $result['blocking']) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "No repeatable candidate-operation benchmark regressions.\n");
