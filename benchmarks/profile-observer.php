<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\BenchmarkProfile;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;
use Oeltima\SimpleQuery\Benchmark\ScenarioName;
use Oeltima\SimpleQuery\Benchmark\ScenarioRequest;

/** @var array<string, false|string> $options */
$options = getopt('', ['bindings:', 'rounds:']);
$bindings = filter_var($options['bindings'] ?? '50', FILTER_VALIDATE_INT);
$rounds = filter_var($options['rounds'] ?? '10', FILTER_VALIDATE_INT);
if (!is_int($bindings) || !in_array($bindings, [1, 10, 50], true) || !is_int($rounds) || $rounds < 1) {
    throw new RuntimeException('Bindings must be 1, 10, or 50 and rounds must be a positive integer.');
}

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$scenario = ScenarioName::from(['name' => 'observer_bindings_' . $bindings]);
$prepared = ScenarioCatalog::prepare(new ScenarioRequest($scenario, BenchmarkProfile::Reference));
$operation = $prepared->operations['observer_noop'] ?? null;
if (!$operation instanceof Closure) {
    throw new RuntimeException('Observer profile operation is unavailable.');
}

$result = null;
for ($round = 0; $round < $rounds; ++$round) {
    $result = $operation();
}

fwrite(STDOUT, json_encode([
    'bindings' => $bindings,
    'calls' => $rounds * 1_000,
    'correctness' => $result,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
