<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Benchmark\NoiseAnalysis;

require __DIR__ . '/bootstrap.php';

/** @var array<string, false|string> $options */
$options = getopt('', ['autoload:', 'suite:', 'profile:', 'iterations:', 'warmups:', 'runs:']);
$autoload = $options['autoload'] ?? dirname(__DIR__) . '/vendor/autoload.php';
$suite = $options['suite'] ?? 'ci';
$profile = $options['profile'] ?? 'ci';
$iterations = filter_var($options['iterations'] ?? 9, FILTER_VALIDATE_INT);
$warmups = filter_var($options['warmups'] ?? 3, FILTER_VALIDATE_INT);
$runCount = filter_var($options['runs'] ?? 5, FILTER_VALIDATE_INT);
if (
    !is_string($autoload)
    || !is_file($autoload)
    || !is_string($suite)
    || !is_string($profile)
    || !is_int($iterations)
    || $iterations < 1
    || $iterations % 2 === 0
    || !is_int($warmups)
    || $warmups < 1
    || !is_int($runCount)
    || $runCount < 3
) {
    throw new RuntimeException('Invalid identical-source noise-control arguments.');
}

$run = static function () use ($autoload, $suite, $profile, $iterations, $warmups): array {
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
        throw new RuntimeException('Identical-source noise-control run failed: ' . implode("\n", $output));
    }
    $decoded = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Identical-source noise-control run emitted an invalid envelope.');
    }

    return $decoded;
};

$runs = [];
for ($index = 0; $index < $runCount; ++$index) {
    $runs[] = $run();
}

$sourceIdentity = null;
foreach ($runs as $runEnvelope) {
    $scenarios = $runEnvelope['scenarios'] ?? null;
    $firstScenario = is_array($scenarios) ? reset($scenarios) : false;
    $environment = is_array($firstScenario) ? ($firstScenario['environment'] ?? null) : null;
    $source = is_array($environment) ? ($environment['source'] ?? null) : null;
    if (!is_array($source)) {
        throw new RuntimeException('Identical-source noise control has no source identity.');
    }
    $identity = json_encode($source, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $sourceIdentity ??= $identity;
    if (!hash_equals($sourceIdentity, $identity)) {
        throw new RuntimeException('Noise-control runs did not use identical source metadata.');
    }
}

$analysis = NoiseAnalysis::repeatedIdenticalSource($runs);
$source = json_decode($sourceIdentity ?? '{}', true, 512, JSON_THROW_ON_ERROR);

fwrite(STDOUT, json_encode([
    'schema_version' => 2,
    'benchmark' => 'php-simplequery-identical-source-noise-control',
    'collected_at' => gmdate(DATE_ATOM),
    'source' => $source,
    'sampling' => [
        'runs' => $runCount,
        'warmups_per_run' => $warmups,
        'samples_per_operation_per_run' => $iterations,
        'fresh_process_per_operation' => true,
    ],
    'policy' => [
        'relative_percentages_ignored_when' =>
            'both medians are below the sub-millisecond ceiling and the absolute change does not exceed the floor',
        'remeasure_floor_per_host' => true,
    ],
    'analysis' => $analysis,
    'runs' => $runs,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
