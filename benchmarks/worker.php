<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\MeasurementRequest;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;
use Oeltima\SimpleQuery\Benchmark\ScenarioName;
use Oeltima\SimpleQuery\Benchmark\ScenarioRequest;

/** @var array<string, false|string> $options */
$options = getopt('', ['scenario:', 'operation:', 'profile:', 'iterations:', 'warmups:', 'autoload:']);
$scenario = $options['scenario'] ?? null;
$operation = $options['operation'] ?? null;
$profile = $options['profile'] ?? null;
$iterations = filter_var($options['iterations'] ?? null, FILTER_VALIDATE_INT);
$warmups = filter_var($options['warmups'] ?? null, FILTER_VALIDATE_INT);
$autoload = $options['autoload'] ?? null;
if (
    !is_string($scenario)
    || ($operation !== null && (!is_string($operation) || $operation === ''))
    || !is_string($profile)
    || !is_int($iterations)
    || !is_int($warmups)
    || !is_string($autoload)
    || !is_file($autoload)
) {
    throw new RuntimeException('Invalid benchmark worker arguments.');
}
if (!in_array($profile, ['ci', 'reference'], true)) {
    throw new RuntimeException(sprintf('Unknown benchmark profile "%s".', $profile));
}

require $autoload;
require __DIR__ . '/bootstrap.php';

$packageRoot = dirname($autoload, 2);
$scenarioName = ScenarioName::from(['name' => $scenario]);
$prepared = ScenarioCatalog::prepare(new ScenarioRequest($scenarioName, $profile));

if ($operation !== null) {
    $operationClosure = $prepared['operations'][$operation] ?? null;
    if (!$operationClosure instanceof Closure) {
        throw new RuntimeException(sprintf(
            'Operation "%s" is not part of benchmark scenario "%s".',
            $operation,
            $scenario,
        ));
    }
    $environment = Harness::environment([
        'package_root' => $packageRoot,
        'pdo' => $prepared['pdo'],
        'target' => 'sqlite',
    ]);
    Harness::assertTimingInstrumentationDisabled($environment);
    $before = Harness::resourceSnapshot();
    $measurement = Harness::measure(MeasurementRequest::from(
        [$operation => $operationClosure],
        ['warmups' => $warmups, 'iterations' => $iterations],
    ));
    $after = Harness::resourceSnapshot();

    $report = [
        'schema_version' => 2,
        'scenario' => $scenario,
        'operation' => $operation,
        'profile' => $profile,
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

    fwrite(
        STDOUT,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    );
    exit(0);
}

$operationReports = [];
foreach (array_keys($prepared['operations']) as $operationName) {
    $command = [
        PHP_BINARY,
        '-d',
        'pcov.enabled=0',
        '-d',
        'xdebug.mode=off',
        __FILE__,
        '--scenario=' . $scenario,
        '--operation=' . $operationName,
        '--profile=' . $profile,
        '--iterations=' . $iterations,
        '--warmups=' . $warmups,
        '--autoload=' . $autoload,
    ];
    $output = [];
    $status = 0;
    exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(sprintf(
            'Operation worker %s/%s failed: %s',
            $scenario,
            $operationName,
            implode("\n", $output),
        ));
    }
    $decoded = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf(
            'Operation worker %s/%s emitted an invalid report.',
            $scenario,
            $operationName,
        ));
    }
    $operationReports[$operationName] = $decoded;
}

$commonDigest = null;
$summaries = [];
$phpPeaks = [];
$rssPeaks = [];
$allocatedDeltas = [];
$descriptorDeltas = [];
$environment = null;
foreach ($operationReports as $operationName => $operationReport) {
    $correctness = $operationReport['correctness'] ?? null;
    $digest = is_array($correctness) ? ($correctness['common_digest'] ?? null) : null;
    if (!is_string($digest)) {
        throw new RuntimeException(sprintf('Operation worker %s/%s emitted no digest.', $scenario, $operationName));
    }
    $commonDigest ??= $digest;
    if (!hash_equals($commonDigest, $digest)) {
        throw new RuntimeException(sprintf('Correctness parity failed for operation %s.', $operationName));
    }

    $measurement = $operationReport['measurement'] ?? null;
    $operations = is_array($measurement) ? ($measurement['operations'] ?? null) : null;
    $summary = is_array($operations) ? ($operations[$operationName] ?? null) : null;
    if (!is_array($summary)) {
        throw new RuntimeException(sprintf(
            'Operation worker %s/%s emitted no measurement.',
            $scenario,
            $operationName,
        ));
    }

    $memory = $operationReport['memory'] ?? null;
    $resourceDelta = $operationReport['resource_delta'] ?? null;
    if (!is_array($memory) || !is_array($resourceDelta)) {
        throw new RuntimeException(sprintf(
            'Operation worker %s/%s emitted no resource evidence.',
            $scenario,
            $operationName,
        ));
    }
    $phpPeak = $memory['php_peak_allocated_bytes'] ?? null;
    $rssPeak = $memory['process_max_rss_bytes'] ?? null;
    $allocatedDelta = $resourceDelta['allocated_bytes'] ?? null;
    $descriptorDelta = $resourceDelta['open_file_descriptors'] ?? null;
    if (!is_int($phpPeak) || !is_int($allocatedDelta)) {
        throw new RuntimeException(sprintf(
            'Operation worker %s/%s emitted invalid resource evidence.',
            $scenario,
            $operationName,
        ));
    }

    $summary['worker'] = [
        'fresh_process' => true,
        'php_peak_allocated_bytes' => $phpPeak,
        'process_max_rss_bytes' => is_int($rssPeak) ? $rssPeak : null,
        'rss_source' => $memory['rss_source'] ?? null,
        'allocated_delta_bytes' => $allocatedDelta,
        'open_file_descriptor_delta' => is_int($descriptorDelta) ? $descriptorDelta : null,
    ];
    $normalization = $prepared['normalization'] ?? null;
    if (is_array($normalization)) {
        $items = $normalization['items'];
        $unit = $normalization['unit'];
        $samples = $summary['samples_ms'] ?? null;
        $minimum = $summary['minimum_ms'] ?? null;
        $median = $summary['median_ms'] ?? null;
        $maximum = $summary['maximum_ms'] ?? null;
        if (
            !is_array($samples)
            || (!is_int($minimum) && !is_float($minimum))
            || (!is_int($median) && !is_float($median))
            || (!is_int($maximum) && !is_float($maximum))
        ) {
            throw new RuntimeException(sprintf('Scenario %s has invalid time normalization.', $scenario));
        }
        $perItemSamples = [];
        foreach ($samples as $sample) {
            if (!is_int($sample) && !is_float($sample)) {
                throw new RuntimeException(sprintf('Scenario %s has an invalid timing sample.', $scenario));
            }
            $perItemSamples[] = round(((float) $sample) / $items, 9);
        }
        $summary['time_per_item'] = [
            'unit' => $unit,
            'items_per_sample' => $items,
            'samples_ms' => $perItemSamples,
            'minimum_ms' => round($minimum / $items, 9),
            'median_ms' => round($median / $items, 9),
            'maximum_ms' => round($maximum / $items, 9),
        ];
    }
    $summaries[$operationName] = $summary;
    $phpPeaks[] = $phpPeak;
    if (is_int($rssPeak)) {
        $rssPeaks[] = $rssPeak;
    }
    $allocatedDeltas[] = $allocatedDelta;
    if (is_int($descriptorDelta)) {
        $descriptorDeltas[] = abs($descriptorDelta);
    }
    $candidateEnvironment = $operationReport['environment'] ?? null;
    if ($environment === null && is_array($candidateEnvironment)) {
        $environment = $candidateEnvironment;
    }
}

if ($commonDigest === null || $environment === null) {
    throw new RuntimeException(sprintf('Scenario %s produced no operation reports.', $scenario));
}

$report = [
    'schema_version' => 2,
    'scenario' => $scenario,
    'profile' => $profile,
    'dimensions' => $prepared['dimensions'],
    'normalization' => $prepared['normalization'] ?? null,
    'environment' => $environment,
    'correctness' => [
        'accepted' => true,
        'digest_algorithm' => 'sha256-json',
        'common_digest' => $commonDigest,
    ],
    'measurement' => [
        'setup_timed' => false,
        'warmup_iterations' => $warmups,
        'sample_iterations' => $iterations,
        'sample_order' => 'fresh-process-per-operation',
        'operations' => $summaries,
    ],
    'worker_policy' => [
        'fresh_process_per_operation' => true,
        'fixture_setup_excluded' => true,
    ],
    'memory' => [
        'php_peak_allocated_bytes' => max($phpPeaks),
        'process_max_rss_bytes' => $rssPeaks === [] ? null : max($rssPeaks),
        'rss_source' => 'maximum-across-fresh-operation-workers',
    ],
    'resource_delta' => [
        'allocated_bytes' => max($allocatedDeltas),
        'open_file_descriptors' => $descriptorDeltas === [] ? null : max($descriptorDeltas),
        'aggregation' => 'maximum-across-fresh-operation-workers',
    ],
];

fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
