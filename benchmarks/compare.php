<?php

declare(strict_types=1);

/** @var array<string, false|string> $options */
$options = getopt('', ['baseline-autoload:', 'candidate-autoload:', 'profile:', 'iterations:', 'warmups:']);
$baseline = $options['baseline-autoload'] ?? null;
$candidate = $options['candidate-autoload'] ?? dirname(__DIR__) . '/vendor/autoload.php';
$profile = $options['profile'] ?? 'ci';
$iterations = $options['iterations'] ?? '5';
$warmups = $options['warmups'] ?? '1';
if (!is_string($baseline) || !is_file($baseline) || !is_string($candidate) || !is_file($candidate)) {
    throw new RuntimeException('Both baseline and candidate autoloaders are required.');
}

$run = static function (string $autoload) use ($profile, $iterations, $warmups): array {
    $command = [
        PHP_BINARY,
        '-d',
        'pcov.enabled=0',
        '-d',
        'xdebug.mode=off',
        __DIR__ . '/run.php',
        '--suite=baseline',
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

$baselineRun = $run($baseline);
$candidateRun = $run($candidate);
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

fwrite(STDOUT, json_encode([
    'schema_version' => 2,
    'benchmark' => 'v0.1.0-to-candidate-comparison',
    'collected_at' => gmdate(DATE_ATOM),
    'execution_order' => ['baseline', 'candidate'],
    'fresh_process_per_scenario' => true,
    'correctness_parity' => true,
    'correctness_digests' => $baselineDigests,
    'baseline' => $baselineRun,
    'candidate' => $candidateRun,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
