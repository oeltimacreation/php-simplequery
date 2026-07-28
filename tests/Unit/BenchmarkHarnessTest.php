<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Benchmark\BenchmarkSuite;
use Oeltima\SimpleQuery\Benchmark\ComparisonAnalysis;
use Oeltima\SimpleQuery\Benchmark\EnvironmentRequest;
use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\MeasurementRequest;
use Oeltima\SimpleQuery\Benchmark\QueryPlanEvidence;
use Oeltima\SimpleQuery\Benchmark\ScenarioCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BenchmarkHarnessTest extends TestCase
{
    public function testMeasurementEmitsRawSamplesAndCorrectnessDigest(): void
    {
        $result = Harness::measure(MeasurementRequest::from(
            [
                'a' => static fn (): array => ['value' => 1],
                'b' => static fn (): array => ['value' => 1],
            ],
            ['warmups' => 1, 'iterations' => 3],
        ));

        $correctness = $result['correctness'] ?? null;
        $measurement = $result['measurement'] ?? null;
        self::assertIsArray($correctness);
        self::assertIsArray($measurement);
        $operations = $measurement['operations'] ?? null;
        self::assertIsArray($operations);
        $operationA = $operations['a'] ?? null;
        $operationB = $operations['b'] ?? null;
        self::assertIsArray($operationA);
        self::assertIsArray($operationB);
        $samples = $operationA['samples_ms'] ?? null;
        self::assertIsArray($samples);

        self::assertTrue($correctness['accepted']);
        self::assertSame('alternating-forward-reverse', $measurement['sample_order']);
        self::assertCount(3, $samples);
        self::assertSame(
            $operationA['correctness_digest'],
            $operationB['correctness_digest'],
        );
    }

    public function testMeasurementRejectsCorrectnessMismatchBeforeTiming(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Correctness parity failed');

        Harness::measure(MeasurementRequest::from(
            [
                'a' => static fn (): int => 1,
                'b' => static fn (): int => 2,
            ],
            ['warmups' => 1, 'iterations' => 3],
        ));
    }

    public function testMeasurementRejectsEvenSampleCount(): void
    {
        $this->expectException(RuntimeException::class);

        Harness::measure(MeasurementRequest::from(
            ['operation' => static fn (): int => 1],
            ['warmups' => 1, 'iterations' => 2],
        ));
    }

    public function testCatalogExposesEveryMaintainedCiScenario(): void
    {
        $scenarios = ScenarioCatalog::suite(BenchmarkSuite::Ci);

        self::assertCount(22, $scenarios);
        foreach (
            [
            'compiler_shapes',
            'batch_compile',
            'hydration',
            'cursor_exhaustion',
            'observer',
            'batch_execute',
            'transactions',
            'lifecycle',
            'migration_query',
            'production_report_compile',
            'production_count_compile',
            'production_report_execute',
            'production_batch_execute',
            ] as $scenario
        ) {
            self::assertContains($scenario, $scenarios);
        }
    }

    public function testEnvironmentAndMemoryEnvelopeIsSerializable(): void
    {
        $environment = Harness::environment(EnvironmentRequest::from([
            'package_root' => dirname(__DIR__, 2),
        ]));
        $memory = Harness::memory();
        $php = $environment['php'] ?? null;
        $source = $environment['source'] ?? null;
        self::assertIsArray($php);
        self::assertIsArray($source);

        self::assertSame(PHP_VERSION, $php['version']);
        self::assertArrayHasKey('commit', $source);
        self::assertGreaterThan(0, $memory['php_peak_allocated_bytes']);
        self::assertJson(json_encode([$environment, $memory], JSON_THROW_ON_ERROR));
    }

    public function testComparisonAnalysisFlagsOnlyRegressionsAboveTheReviewThreshold(): void
    {
        $baseline = $this->comparisonRun(['compile' => 10.0, 'execute' => 20.0]);
        $candidate = $this->comparisonRun(['compile' => 11.0, 'execute' => 22.1]);

        $analysis = ComparisonAnalysis::between($baseline, $candidate);
        $measurements = $analysis['measurements']['production'] ?? null;
        self::assertIsArray($measurements);
        self::assertFalse($measurements['compile']['review_required']);
        self::assertSame(10.0, $measurements['compile']['change_percent']);
        self::assertTrue($measurements['execute']['review_required']);
        self::assertSame(10.5, $measurements['execute']['change_percent']);
    }

    public function testComparisonAnalysisKeepsZeroBaselineSerializable(): void
    {
        $analysis = ComparisonAnalysis::between(
            $this->comparisonRun(['operation' => 0.0]),
            $this->comparisonRun(['operation' => 1.0]),
        );
        $measurement = $analysis['measurements']['production']['operation'] ?? null;
        self::assertIsArray($measurement);
        self::assertNull($measurement['change_percent']);
        self::assertTrue($measurement['review_required']);
        self::assertJson(json_encode($analysis, JSON_THROW_ON_ERROR));
    }

    public function testQueryPlanEvidenceGatesResultParityAndExpectedSqlitePlans(): void
    {
        $evidence = QueryPlanEvidence::collect(200);
        $range = $evidence['range'] ?? null;
        $wrapped = $evidence['function_wrapped'] ?? null;
        self::assertIsArray($range);
        self::assertIsArray($wrapped);
        self::assertIsString($range['sql'] ?? null);
        self::assertIsString($wrapped['sql'] ?? null);
        self::assertIsArray($range['plan'] ?? null);
        self::assertIsArray($wrapped['plan'] ?? null);
        $rangePlan = $range['plan'][0] ?? null;
        $wrappedPlan = $wrapped['plan'][0] ?? null;
        self::assertIsString($rangePlan);
        self::assertIsString($wrappedPlan);
        self::assertGreaterThan(0, $evidence['result_rows']);
        self::assertStringContainsString('created_at" >= ?', $range['sql']);
        self::assertStringContainsString('date(created_at) = ?', $wrapped['sql']);
        self::assertStringContainsString('USING COVERING INDEX', $rangePlan);
        self::assertStringContainsString('SCAN plan_events', $wrappedPlan);
    }

    /**
     * @param array<string, float> $medians
     * @return array<string, mixed>
     */
    private function comparisonRun(array $medians): array
    {
        $operations = [];
        foreach ($medians as $name => $median) {
            $operations[$name] = ['median_ms' => $median];
        }

        return [
            'scenarios' => [[
                'scenario' => 'production',
                'measurement' => ['operations' => $operations],
            ]],
        ];
    }
}
