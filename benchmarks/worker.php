<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;

/** @var array<string, false|string> $options */
$options = getopt('', ['scenario:', 'profile:', 'iterations:', 'warmups:', 'autoload:']);
$scenario = $options['scenario'] ?? null;
$profile = $options['profile'] ?? null;
$iterations = filter_var($options['iterations'] ?? null, FILTER_VALIDATE_INT);
$warmups = filter_var($options['warmups'] ?? null, FILTER_VALIDATE_INT);
$autoload = $options['autoload'] ?? null;
if (
    !is_string($scenario)
    || !is_string($profile)
    || !is_int($iterations)
    || !is_int($warmups)
    || !is_string($autoload)
    || !is_file($autoload)
) {
    throw new RuntimeException('Invalid benchmark worker arguments.');
}

require $autoload;
require __DIR__ . '/Harness.php';
require __DIR__ . '/ScenarioCatalog.php';

$packageRoot = dirname($autoload, 2);
$prepared = ScenarioCatalog::prepare($scenario, $profile);
$environment = Harness::environment($packageRoot, $prepared['pdo'], 'sqlite');
Harness::assertTimingInstrumentationDisabled($environment);
$before = Harness::resourceSnapshot();
$measurement = Harness::measure($prepared['operations'], $warmups, $iterations);
$after = Harness::resourceSnapshot();

$report = [
    'schema_version' => 2,
    'scenario' => $scenario,
    'profile' => $profile,
    'dimensions' => $prepared['dimensions'],
    'environment' => $environment,
    'correctness' => $measurement['correctness'],
    'measurement' => $measurement['measurement'],
    'memory' => Harness::memory(),
    'resource_delta' => [
        'allocated_bytes' => $after['allocated_bytes'] - $before['allocated_bytes'],
        'open_file_descriptors' => $before['open_file_descriptors'] === null
            || $after['open_file_descriptors'] === null
            ? null
            : $after['open_file_descriptors'] - $before['open_file_descriptors'],
    ],
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
