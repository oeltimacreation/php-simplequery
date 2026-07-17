<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$certify = in_array('--certify', $argv, true);
$requiredFiles = [
    'composer.json',
    'phpunit.xml.dist',
    'phpunit.coverage.xml.dist',
    'phpstan.neon',
    'phpcs.xml.dist',
    '.github/workflows/ci.yml',
    '.github/workflows/proxy-probes.yml',
    'docs/public-api.md',
    'docs/testing-architecture.md',
    'docs/evidence/deployment-inventory.json',
    'docs/evidence/fixture-baseline.json',
    'docs/evidence/research-dossiers.md',
    'docs/evidence/direct-behavior-matrix.md',
    'docs/evidence/proxy-behavior-matrix.md',
    'docs/evidence/consumer-requirements-audit.md',
    'docs/evidence/insert-return-audit.md',
    'docs/benchmarking.md',
    'tests/Fixtures/Contracts/aggregate-scalars.json',
    'tests/Fixtures/Contracts/connection-construction.json',
    'tests/Fixtures/Migration/v1.json',
    'tools/database-probes/compose.yaml',
];

$errors = [];
foreach ($requiredFiles as $requiredFile) {
    if (!is_file($root . '/' . $requiredFile)) {
        $errors[] = sprintf('Missing required repository file: %s', $requiredFile);
    }
}

$jsonFiles = [
    'docs/evidence/deployment-inventory.json',
    'docs/evidence/fixture-baseline.json',
    'tests/Fixtures/Contracts/aggregate-scalars.json',
    'tests/Fixtures/Contracts/connection-construction.json',
    'tests/Fixtures/Migration/v1.json',
];
foreach ($jsonFiles as $jsonFile) {
    $contents = @file_get_contents($root . '/' . $jsonFile);
    if (!is_string($contents)) {
        continue;
    }

    try {
        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = sprintf('%s is invalid JSON: %s', $jsonFile, $exception->getMessage());
    }
}

if ($certify && is_file($root . '/docs/evidence/deployment-inventory.json')) {
    $contents = file_get_contents($root . '/docs/evidence/deployment-inventory.json');
    $inventory = is_string($contents) ? json_decode($contents, true) : null;
    $deployments = is_array($inventory) ? ($inventory['deployments'] ?? null) : null;
    if (!is_array($deployments)) {
        $errors[] = 'Deployment inventory has no deployments object.';
    } else {
        foreach (['mariadb', 'mysql', 'sqlite', 'proxysql', 'maxscale'] as $target) {
            $entry = $deployments[$target] ?? null;
            if (!is_array($entry) || ($entry['status'] ?? null) !== 'verified') {
                $errors[] = sprintf('Deployment target %s is not verified.', $target);
            }
            if (!is_array($entry) || !is_string($entry['probe_artifact'] ?? null)) {
                $errors[] = sprintf('Deployment target %s has no probe artifact.', $target);
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf(
    "Repository %s checks passed (%d required records).\n",
    $certify ? 'certification' : 'structural',
    count($requiredFiles),
);
