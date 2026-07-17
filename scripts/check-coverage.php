<?php

declare(strict_types=1);

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) < 2) {
    fwrite(
        STDERR,
        "Usage: check-coverage.php <clover.xml> --line=90 --branch=80 --compiler-line=95 --compiler-branch=90\n",
    );
    exit(2);
}

$reportPath = $arguments[1];
$thresholds = [
    'line' => 90.0,
    'branch' => 80.0,
    'compiler-line' => 95.0,
    'compiler-branch' => 90.0,
];

foreach (array_slice($arguments, 2) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        fwrite(STDERR, sprintf("Unknown coverage option: %s\n", $argument));
        exit(2);
    }

    [$name, $value] = explode('=', substr($argument, 2), 2);
    if (!array_key_exists($name, $thresholds) || !is_numeric($value)) {
        fwrite(STDERR, sprintf("Invalid coverage option: %s\n", $argument));
        exit(2);
    }

    $thresholds[$name] = (float) $value;
}

if (!is_file($reportPath)) {
    $sourceExists = false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $sourceExists = true;
            break;
        }
    }
    if (!$sourceExists) {
        fwrite(STDOUT, "Coverage thresholds are armed; no implementation source exists yet.\n");
        exit(0);
    }

    fwrite(STDERR, sprintf("Coverage report does not exist: %s\n", $reportPath));
    exit(2);
}

$report = simplexml_load_file($reportPath);
if (!$report instanceof SimpleXMLElement || !isset($report->project->metrics)) {
    fwrite(STDERR, "Could not read Clover coverage metrics.\n");
    exit(2);
}

/** @return array{lines: int, covered_lines: int, branches: int, covered_branches: int} */
$readMetrics = static function (SimpleXMLElement $metrics): array {
    $attributes = $metrics->attributes();

    return [
        'lines' => isset($attributes['statements']) ? (int) $attributes['statements'] : 0,
        'covered_lines' => isset($attributes['coveredstatements']) ? (int) $attributes['coveredstatements'] : 0,
        'branches' => isset($attributes['conditionals']) ? (int) $attributes['conditionals'] : 0,
        'covered_branches' => isset($attributes['coveredconditionals']) ? (int) $attributes['coveredconditionals'] : 0,
    ];
};

/** @param array{lines: int, covered_lines: int, branches: int, covered_branches: int} $metrics */
$percentage = static function (array $metrics, string $total, string $covered): ?float {
    if ($metrics[$total] === 0) {
        return null;
    }

    return ($metrics[$covered] / $metrics[$total]) * 100;
};

$overall = $readMetrics($report->project->metrics);
if ($overall['lines'] === 0) {
    fwrite(STDOUT, "Coverage thresholds are armed; Clover contains no implementation statements yet.\n");
    exit(0);
}

$compiler = ['lines' => 0, 'covered_lines' => 0, 'branches' => 0, 'covered_branches' => 0];
$compilerMetrics = $report->xpath('//file[contains(@name, "/Internal/Compiler/")]/metrics');
if ($compilerMetrics === null) {
    $compilerMetrics = [];
}
foreach ($compilerMetrics as $metrics) {
    $fileMetrics = $readMetrics($metrics);
    foreach ($compiler as $key => $value) {
        $compiler[$key] = $value + $fileMetrics[$key];
    }
}

$checks = [
    ['Overall line', $percentage($overall, 'lines', 'covered_lines'), $thresholds['line']],
    ['Overall branch', $percentage($overall, 'branches', 'covered_branches'), $thresholds['branch']],
];
if ($compiler['lines'] > 0) {
    $checks[] = ['Compiler line', $percentage($compiler, 'lines', 'covered_lines'), $thresholds['compiler-line']];
    $checks[] = [
        'Compiler branch',
        $percentage($compiler, 'branches', 'covered_branches'),
        $thresholds['compiler-branch'],
    ];
}

$failed = false;
foreach ($checks as [$label, $actual, $minimum]) {
    if ($actual === null) {
        printf("%s coverage is not reported by this driver.\n", $label);
        continue;
    }

    printf("%s coverage: %.2f%% (minimum %.2f%%)\n", $label, $actual, $minimum);
    $failed = $failed || $actual < $minimum;
}

exit($failed ? 1 : 0);
