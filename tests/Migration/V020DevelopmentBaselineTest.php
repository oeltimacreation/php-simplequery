<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class V020DevelopmentBaselineTest extends TestCase
{
    public function testBaselineIsCompleteAndContainsNoPrivateConsumerMaterial(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/docs/evidence/v0.2.0-development-baseline.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringNotContainsString('/home/', $contents);

        $baseline = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        self::assertSame(1, $baseline['schema_version'] ?? null);
        self::assertSame('v0.2.0', $baseline['baseline'] ?? null);
        self::assertSame('a9928769184c5a74f56da169022073b872889885', $baseline['commit'] ?? null);

        $sourceState = $baseline['source_state'] ?? null;
        self::assertIsArray($sourceState);
        self::assertSame(30, $sourceState['public_php_file_count'] ?? null);
        $publicDigest = $sourceState['public_php_blob_manifest_sha256'] ?? null;
        self::assertIsString($publicDigest);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $publicDigest,
        );

        $queryDigests = $baseline['synthetic_query_digests'] ?? null;
        self::assertIsArray($queryDigests);
        self::assertCount(8, $queryDigests);
        foreach ($queryDigests as $fixture => $digest) {
            self::assertIsString($fixture);
            self::assertStringStartsWith('tests/Fixtures/', $fixture);
            self::assertIsString($digest);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest);
        }

        $coverage = $baseline['coverage'] ?? null;
        self::assertIsArray($coverage);
        foreach (['overall_line', 'overall_branch'] as $metric) {
            $measurement = $coverage[$metric] ?? null;
            self::assertIsArray($measurement);
            self::assertGreaterThanOrEqual($measurement['gate_percent'] ?? 101, $measurement['percent'] ?? 0);
        }
        self::assertGreaterThanOrEqual(
            $coverage['compiler_line_gate_percent'] ?? 101,
            $coverage['compiler_line_percent'] ?? 0,
        );
        self::assertGreaterThanOrEqual(
            $coverage['compiler_branch_gate_percent'] ?? 101,
            $coverage['compiler_branch_percent'] ?? 0,
        );

        $benchmarkControls = $baseline['benchmark_controls'] ?? null;
        self::assertIsArray($benchmarkControls);
        $commands = $benchmarkControls['commands'] ?? null;
        self::assertIsArray($commands);
        self::assertContains('composer benchmark', $commands);
        self::assertContains('composer benchmark:reference', $commands);
        self::assertContains('composer benchmark:soak', $commands);
    }

    public function testCommittedAdoptionEvidenceIsAnonymized(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/evidence/0.3-production-adoption-baseline.md';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringNotContainsString('/home/', $contents);
        self::assertStringNotContainsString('sistemabsensi', strtolower($contents));
        self::assertStringNotContainsString('SELECT ', $contents);
        self::assertStringContainsString('Lexical matches', $contents);
        self::assertStringContainsString('synthetic', $contents);
    }
}
