<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\ComparisonAnalysis;

require __DIR__ . '/bootstrap.php';

/** @var array<string, false|string> $options */
$options = getopt('', [
    'baseline-autoload:',
    'candidate-autoload:',
    'baseline-label:',
    'candidate-label:',
    'suite:',
    'profile:',
    'iterations:',
    'warmups:',
    'source-order:',
    'noise-floor-ms:',
    'noise-report:',
    'allow-review',
]);
$baseline = $options['baseline-autoload'] ?? null;
$candidate = $options['candidate-autoload'] ?? dirname(__DIR__) . '/vendor/autoload.php';
$baselineLabel = $options['baseline-label'] ?? 'baseline';
$candidateLabel = $options['candidate-label'] ?? 'candidate';
$suite = $options['suite'] ?? 'baseline';
$profile = $options['profile'] ?? 'ci';
$iterations = $options['iterations'] ?? '5';
$warmups = $options['warmups'] ?? '1';
$sourceOrder = $options['source-order'] ?? 'baseline-first';
$noiseFloor = filter_var($options['noise-floor-ms'] ?? 0.0, FILTER_VALIDATE_FLOAT);
$noiseReport = $options['noise-report'] ?? null;
$allowReview = array_key_exists('allow-review', $options);
if (!is_string($baseline) || !is_file($baseline) || !is_string($candidate) || !is_file($candidate)) {
    throw new RuntimeException('Both baseline and candidate autoloaders are required.');
}
if (
    !is_string($baselineLabel)
    || trim($baselineLabel) === ''
    || !is_string($candidateLabel)
    || trim($candidateLabel) === ''
) {
    throw new RuntimeException('Baseline and candidate labels must be non-empty strings.');
}
if (!is_string($sourceOrder) || !in_array($sourceOrder, ['baseline-first', 'candidate-first'], true)) {
    throw new RuntimeException('Source order must be baseline-first or candidate-first.');
}
if (!is_float($noiseFloor) || $noiseFloor < 0.0) {
    throw new RuntimeException('The absolute timing noise floor must be a non-negative number.');
}
if ($noiseReport !== null && (!is_string($noiseReport) || !is_file($noiseReport))) {
    throw new RuntimeException('The identical-source noise report does not exist.');
}

$sameSourceRanges = [];
if (is_string($noiseReport)) {
    $noiseContents = file_get_contents($noiseReport);
    if (!is_string($noiseContents)) {
        throw new RuntimeException('The identical-source noise report cannot be read.');
    }
    $noiseData = json_decode($noiseContents, true, 512, JSON_THROW_ON_ERROR);
    $analysis = is_array($noiseData) ? ($noiseData['analysis'] ?? null) : null;
    $reportedFloor = is_array($analysis) ? ($analysis['absolute_noise_floor_ms'] ?? null) : null;
    $noiseMeasurements = is_array($analysis) ? ($analysis['measurements'] ?? null) : null;
    if ((!is_int($reportedFloor) && !is_float($reportedFloor)) || !is_array($noiseMeasurements)) {
        throw new RuntimeException('The identical-source noise report is invalid.');
    }
    $noiseFloor = (float) $reportedFloor;
    foreach ($noiseMeasurements as $scenarioName => $operations) {
        if (!is_string($scenarioName) || !is_array($operations)) {
            throw new RuntimeException('The identical-source noise report contains an invalid scenario.');
        }
        foreach ($operations as $operationName => $measurement) {
            $range = is_array($measurement) ? ($measurement['range_ms'] ?? null) : null;
            if (!is_string($operationName) || (!is_int($range) && !is_float($range))) {
                throw new RuntimeException('The identical-source noise report contains an invalid operation.');
            }
            $sameSourceRanges[$scenarioName][$operationName] = (float) $range;
        }
    }
}

$run = static function (string $autoload) use ($suite, $profile, $iterations, $warmups): array {
    $command = [
        PHP_BINARY,
        '-d',
        'pcov.enabled=0',
        '-d',
        'xdebug.mode=off',
        __DIR__ . '/run.php',
        '--suite=' . $suite,
        '--profile=' . $profile,
        '--iterations=' . $iterations,
        '--warmups=' . $warmups,
        '--autoload=' . $autoload,
    ];
    $output = [];
    $status = 0;
    exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Baseline comparison run failed: ' . implode("\n", $output));
    }
    $decoded = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Baseline comparison run emitted an invalid envelope.');
    }

    return $decoded;
};

if ($sourceOrder === 'baseline-first') {
    $baselineRun = $run($baseline);
    $candidateRun = $run($candidate);
} else {
    $candidateRun = $run($candidate);
    $baselineRun = $run($baseline);
}
$digests = static function (array $run): array {
    $result = [];
    $scenarios = $run['scenarios'] ?? null;
    if (!is_array($scenarios)) {
        throw new RuntimeException('Comparison run has no scenarios.');
    }
    foreach ($scenarios as $scenario) {
        if (!is_array($scenario) || !is_string($scenario['scenario'] ?? null)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }
        $correctness = $scenario['correctness'] ?? null;
        if (!is_array($correctness) || !is_string($correctness['common_digest'] ?? null)) {
            throw new RuntimeException('Comparison run contains no correctness digest.');
        }
        $result[$scenario['scenario']] = $correctness['common_digest'];
    }

    return $result;
};

$baselineDigests = $digests($baselineRun);
$candidateDigests = $digests($candidateRun);
if ($baselineDigests !== $candidateDigests) {
    throw new RuntimeException('Baseline and candidate correctness digests differ.');
}
$performanceReview = ComparisonAnalysis::between(
    $baselineRun,
    $candidateRun,
    absoluteNoiseFloorMs: $noiseFloor,
    sameSourceRanges: $sameSourceRanges,
);
$blocking = [];
foreach ($performanceReview['measurements'] as $scenario => $operations) {
    foreach ($operations as $operation => $measurement) {
        if (($measurement['review_required'] ?? false) === true) {
            $blocking[] = sprintf('%s/%s', $scenario, $operation);
        }
    }
}
if ($blocking !== [] && !$allowReview) {
    throw new RuntimeException('Unexplained benchmark regressions: ' . implode(', ', $blocking));
}

fwrite(STDOUT, json_encode([
    'schema_version' => 2,
    'benchmark' => 'php-simplequery-release-comparison',
    'collected_at' => gmdate(DATE_ATOM),
    'baseline_label' => $baselineLabel,
    'candidate_label' => $candidateLabel,
    'execution_order' => $sourceOrder === 'baseline-first'
        ? [$baselineLabel, $candidateLabel]
        : [$candidateLabel, $baselineLabel],
    'fresh_process_per_scenario' => true,
    'correctness_parity' => true,
    'correctness_digests' => $baselineDigests,
    'performance_review' => $performanceReview,
    'baseline' => $baselineRun,
    'candidate' => $candidateRun,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
