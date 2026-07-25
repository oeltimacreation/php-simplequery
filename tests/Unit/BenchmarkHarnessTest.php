<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Benchmark\BenchmarkSuite;
use Oeltima\SimpleQuery\Benchmark\EnvironmentRequest;
use Oeltima\SimpleQuery\Benchmark\Harness;
use Oeltima\SimpleQuery\Benchmark\MeasurementRequest;
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

        self::assertCount(18, $scenarios);
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
}
