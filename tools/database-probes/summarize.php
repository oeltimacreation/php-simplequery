<?php

declare(strict_types=1);

if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: summarize.php <database-probe-result-directory>\n");
    exit(2);
}

$files = glob(rtrim($argv[1], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json');
if ($files === false) {
    $files = [];
}
sort($files);
$summary = [];
$failed = false;

foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (!is_string($contents)) {
        continue;
    }

    $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($report)) {
        continue;
    }

    $statuses = [];
    $observations = $report['observations'] ?? null;
    if (!is_array($observations)) {
        $observations = [];
    }
    foreach ($observations as $observation) {
        if (!is_array($observation) || !is_string($observation['status'] ?? null)) {
            continue;
        }
        $statuses[$observation['status']] = ($statuses[$observation['status']] ?? 0) + 1;
    }

    $failed = $failed || ($statuses['failed'] ?? 0) > 0;
    $runtime = $report['runtime'] ?? null;
    if (!is_array($runtime)) {
        $runtime = [];
    }
    $summary[] = [
        'file' => basename($file),
        'target' => $report['target'] ?? null,
        'engine' => $report['engine'] ?? null,
        'server_version' => $runtime['server_version'] ?? null,
        'statuses' => $statuses,
    ];
}

fwrite(
    STDOUT,
    json_encode(['schema_version' => 1, 'reports' => $summary], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
);

exit($failed ? 1 : 0);
