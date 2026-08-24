<?php

declare(strict_types=1);

$root = dirname(__DIR__);
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$certify = in_array('--certify', $arguments, true);
$requiredFiles = [
    'composer.json',
    'phpunit.xml.dist',
    'phpunit.coverage.xml.dist',
    'phpunit.branch-coverage.xml.dist',
    'phpstan.neon',
    'phpstan.consumer.neon',
    'phpcs.xml.dist',
    '.github/workflows/ci.yml',
    '.github/workflows/proxy-probes.yml',
    'docs/README.md',
    'docs/guides/getting-started.md',
    'docs/maintainers/README.md',
    'docs/plans/README.md',
    'docs/reference/public-api.md',
    'docs/maintainers/testing-architecture.md',
    'docs/evidence/deployment-inventory.json',
    'docs/evidence/fixture-baseline.json',
    'docs/evidence/research-dossiers.md',
    'docs/evidence/direct-behavior-matrix.md',
    'docs/evidence/proxy-behavior-matrix.md',
    'docs/evidence/consumer-requirements-audit.md',
    'docs/evidence/consumer-audit-baseline.json',
    'docs/evidence/migration-validation.json',
    'docs/evidence/migration-benchmark.json',
    'docs/evidence/0.2-associative-hydration-experiment.md',
    'docs/evidence/0.3-transaction-and-exception-ergonomics.md',
    'docs/evidence/0.3-architecture-and-quality-audit.md',
    'docs/evidence/0.3-projection-ergonomics-spike.md',
    'docs/evidence/0.5-maintainability-and-performance.md',
    'docs/evidence/0.6-phase-0-baseline-and-scope.md',
    'docs/evidence/0.6-phase-1-attributable-performance.md',
    'docs/evidence/0.6-phase-2-compiler-and-binding-efficiency.md',
    'docs/evidence/0.6-phase-3-execution-hydration-and-resource-efficiency.md',
    'docs/evidence/0.6-phase-4-code-quality-and-maintainer-efficiency.md',
    'docs/evidence/0.6-phase-5-user-experience-and-documentation.md',
    'docs/evidence/0.6-phase-6-release-certification.md',
    'docs/maintainers/migration-validation.md',
    'docs/maintainers/migration-review.md',
    'docs/maintainers/benchmarking.md',
    'tests/Fixtures/Contracts/aggregate-scalars.json',
    'tests/Fixtures/Contracts/connection-construction.json',
    'tests/Fixtures/Contracts/public-api.json',
    'tests/Fixtures/Migration/v1.json',
    'tests/Fixtures/Migration/representative-slices.json',
    'tests/Fixtures/Adoption/manifest.json',
    'tests/Fixtures/Compiler/mariadb.json',
    'tests/Fixtures/Compiler/mysql.json',
    'tests/Fixtures/Compiler/sqlite.json',
    'tests/Fixtures/Compiler/feature-coverage.json',
    'examples/compiler-assertions.php',
    'examples/beginner/first-query.php',
    'examples/beginner/filter-and-update.php',
    'examples/beginner/transaction.php',
    'examples/query-building.php',
    'examples/sqlite-compiler-smoke.php',
    'examples/sqlite-execution.php',
    'examples/sqlite-streaming-report.php',
    'examples/sqlite-batch-write.php',
    'examples/sqlite-transactions.php',
    'examples/sqlite-migration-slice.php',
    'tools/database-probes/compose.yaml',
    'tools/database-probes/execution-smoke.php',
    'tools/database-probes/transaction-smoke.php',
    'tools/database-probes/src/SqliteImmediateProbe.php',
    'tools/database-probes/src/SqliteContentionProbe.php',
    'tools/database-probes/sqlite-contention-worker.php',
    'tools/database-probes/run.php',
    'scripts/run-branch-coverage.php',
    'benchmarks/Harness.php',
    'benchmarks/NoiseAnalysis.php',
    'benchmarks/ScenarioCatalog.php',
    'benchmarks/compare.php',
    'benchmarks/engine.php',
    'benchmarks/noise.php',
    'benchmarks/run.php',
    'benchmarks/worker.php',
    'tests/Fixtures/Consumer/static-analysis.php',
    'scripts/check-documentation-links.php',
    'scripts/check-release-consistency.php',
    'scripts/public-api-manifest.php',
    'tools/quality/ReleaseConsistencyChecker.php',
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
    'docs/evidence/consumer-audit-baseline.json',
    'docs/evidence/migration-validation.json',
    'docs/evidence/migration-benchmark.json',
    'tests/Fixtures/Contracts/aggregate-scalars.json',
    'tests/Fixtures/Contracts/connection-construction.json',
    'tests/Fixtures/Contracts/public-api.json',
    'tests/Fixtures/Migration/v1.json',
    'tests/Fixtures/Migration/representative-slices.json',
    'tests/Fixtures/Adoption/manifest.json',
    'tests/Fixtures/Compiler/mariadb.json',
    'tests/Fixtures/Compiler/mysql.json',
    'tests/Fixtures/Compiler/sqlite.json',
    'tests/Fixtures/Compiler/feature-coverage.json',
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
