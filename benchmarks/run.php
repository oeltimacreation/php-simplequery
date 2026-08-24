<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\BenchmarkSuite;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;

require __DIR__ . '/bootstrap.php';

/** @var array<string, false|string> $options */
$options = getopt('', ['suite:', 'scenario:', 'profile:', 'iterations:', 'warmups:', 'autoload:', 'list']);
$environmentSuite = getenv('SIMPLEQUERY_BENCHMARK_DEFAULT_SUITE');
$defaultSuite = is_string($environmentSuite) && $environmentSuite !== '' ? $environmentSuite : 'ci';
$suiteName = is_string($options['suite'] ?? null) ? $options['suite'] : $defaultSuite;
$profile = is_string($options['profile'] ?? null) ? $options['profile'] : 'ci';
$iterations = filter_var($options['iterations'] ?? 5, FILTER_VALIDATE_INT);
$warmups = filter_var($options['warmups'] ?? 1, FILTER_VALIDATE_INT);
$autoload = is_string($options['autoload'] ?? null)
    ? $options['autoload']
    : dirname(__DIR__) . '/vendor/autoload.php';

if ($profile !== 'ci' && $profile !== 'reference') {
    throw new RuntimeException('Benchmark profile must be ci or reference.');
}
if (!is_int($iterations) || $iterations < 1 || $iterations % 2 === 0) {
    throw new RuntimeException('Benchmark iterations must be a positive odd integer.');
}
if (!is_int($warmups) || $warmups < 1) {
    throw new RuntimeException('Benchmark warm-ups must be positive.');
}
if (!is_file($autoload)) {
    throw new RuntimeException(sprintf('Benchmark autoloader does not exist: %s', $autoload));
}

$suite = BenchmarkSuite::tryFrom($suiteName)
    ?? throw new RuntimeException(sprintf('Unknown benchmark suite "%s".', $suiteName));
$scenarios = ScenarioCatalog::suite($suite);
if (array_key_exists('list', $options)) {
    fwrite(STDOUT, implode(PHP_EOL, $scenarios) . PHP_EOL);
    exit(0);
}
if (is_string($options['scenario'] ?? null)) {
    if (!in_array($options['scenario'], $scenarios, true)) {
        throw new RuntimeException('Requested scenario is not part of the selected suite.');
    }
    $scenarios = [$options['scenario']];
}

$reports = [];
foreach ($scenarios as $scenario) {
    $command = [
        PHP_BINARY,
        '-d',
        'pcov.enabled=0',
        '-d',
        'xdebug.mode=off',
        __DIR__ . '/worker.php',
        '--scenario=' . $scenario,
        '--profile=' . $profile,
        '--iterations=' . $iterations,
        '--warmups=' . $warmups,
        '--autoload=' . $autoload,
    ];
    $escaped = array_map('escapeshellarg', $command);
    $output = [];
    $status = 0;
    exec(implode(' ', $escaped) . ' 2>&1', $output, $status);
    $json = implode("\n", $output);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('Scenario %s failed: %s', $scenario, $json));
    }
    $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $correctness = is_array($report) ? ($report['correctness'] ?? null) : null;
    if (!is_array($report) || !is_array($correctness) || ($correctness['accepted'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Scenario %s emitted no accepted correctness result.', $scenario));
    }
    $reports[] = $report;
}

/*
 * Soak memory gate: retained allocation must not grow across timed samples in
 * the same process after warm-up. The tolerance is a portable in-process bound
 * rather than an absolute host threshold.
 *
 * @param list<array<string, mixed>> $reports
 */
$assertSoakMemoryBounds = static function (array $reports): void {
    $toleranceBytes = 256 * 1024;
    foreach ($reports as $report) {
        $scenario = is_string($report['scenario'] ?? null) ? $report['scenario'] : 'unknown';
        $measurement = $report['measurement'] ?? null;
        $operations = is_array($measurement) ? ($measurement['operations'] ?? null) : null;
        if (!is_array($operations)) {
            throw new RuntimeException(sprintf('Soak scenario %s has no operation measurements.', $scenario));
        }
        foreach ($operations as $operationName => $operation) {
            $growth = is_array($operation) ? ($operation['retained_growth_bytes'] ?? null) : null;
            if (!is_int($growth) && !is_float($growth)) {
                throw new RuntimeException(sprintf(
                    'Soak scenario %s/%s lacks retained allocation evidence.',
                    $scenario,
                    (string) $operationName,
                ));
            }
            if ($growth > $toleranceBytes) {
                throw new RuntimeException(sprintf(
                    'Soak memory gate failed for %s/%s: retained growth of %d bytes exceeds %d bytes.',
                    $scenario,
                    (string) $operationName,
                    $growth,
                    $toleranceBytes,
                ));
            }
        }
    }
};

if ($suite === BenchmarkSuite::Soak) {
    $assertSoakMemoryBounds($reports);
}

$scaling = [];
$predicateMedians = [];
foreach ($reports as $report) {
    $name = $report['scenario'] ?? null;
    if (!is_string($name) || !str_starts_with($name, 'compiler_predicates_')) {
        continue;
    }
    $dimensions = $report['dimensions'] ?? null;
    $measurement = $report['measurement'] ?? null;
    $operations = is_array($measurement) ? ($measurement['operations'] ?? null) : null;
    $simpleQuery = is_array($operations) ? ($operations['simplequery'] ?? null) : null;
    $predicates = is_array($dimensions) ? ($dimensions['predicates'] ?? null) : null;
    $median = is_array($simpleQuery) ? ($simpleQuery['median_ms'] ?? null) : null;
    if (is_int($predicates) && (is_int($median) || is_float($median))) {
        $predicateMedians[$predicates] = (float) $median;
    }
}

ksort($predicateMedians);
$previousSize = null;
$previousMedian = null;
foreach ($predicateMedians as $size => $median) {
    $scaling[] = [
        'predicates' => $size,
        'median_ms' => $median,
        'milliseconds_per_predicate' => round($median / $size, 9),
        'size_ratio_from_previous' => $previousSize === null ? null : $size / $previousSize,
        'time_ratio_from_previous' => $previousMedian === null || $previousMedian === 0.0
            ? null
            : round($median / $previousMedian, 6),
    ];
    $previousSize = $size;
    $previousMedian = $median;
}

$runnerRoot = dirname(__DIR__);
$runnerEnvironment = Harness::environment(['package_root' => $runnerRoot]);
$envelope = [
    'schema_version' => 2,
    'benchmark' => 'php-simplequery-reproducible-suite',
    'collected_at' => gmdate(DATE_ATOM),
    'suite' => $suite->value,
    'profile' => $profile,
    'runner_environment' => $runnerEnvironment,
    'policy' => [
        'correctness_required_before_timing_and_after_every_sample' => true,
        'fixture_setup_excluded' => true,
        'fresh_process_per_scenario' => true,
        'fresh_process_per_operation' => true,
        'absolute_timing_noise_floor_supplied_by_comparison' => true,
    ],
    'compiler_scaling' => $scaling,
    'scenarios' => $reports,
];

fwrite(STDOUT, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
