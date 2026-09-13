<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Tools\Quality\BenchmarkComparisonGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BenchmarkComparisonGateTest extends TestCase
{
    /**
     * @param array<mixed> $report
     * @param array<mixed> $other
     */
    #[DataProvider('malformedReports')]
    public function testMalformedReportsAreRejected(array $report, array $other): void
    {
        $this->expectException(RuntimeException::class);
        (new BenchmarkComparisonGate())->evaluate($report, $other);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function malformedReports(): iterable
    {
        $empty = ['performance_review' => ['measurements' => []]];
        yield 'missing review' => [[], $empty];
        yield 'missing measurements' => [['performance_review' => []], $empty];
        yield 'malformed scenario' => [
            ['performance_review' => ['measurements' => ['hydration' => 'invalid']]],
            $empty,
        ];
        yield 'malformed measurement' => [
            ['performance_review' => ['measurements' => ['hydration' => ['simplequery' => 'invalid']]]],
            $empty,
        ];
        yield 'missing review flag' => [
            ['performance_review' => ['measurements' => ['hydration' => ['simplequery' => []]]]],
            $empty,
        ];
        yield 'malformed other report' => [
            $empty,
            ['performance_review' => ['measurements' => ['hydration' => ['simplequery' => ['review_required' => 1]]]]],
        ];
    }

    public function testRepeatableCandidateRegressionBlocks(): void
    {
        $report = $this->report(['hydration' => ['simplequery_associative' => true]]);

        $result = (new BenchmarkComparisonGate())->evaluate($report, $report);

        self::assertSame(['hydration/simplequery_associative'], $result['blocking']);
        self::assertSame([], $result['controls']);
        self::assertSame(['hydration/simplequery_associative'], $result['signals']);
    }

    public function testRepeatableControlSignalsAreRetainedButDoNotBlock(): void
    {
        $report = $this->report([
            'pdo_control_1000' => ['pdo' => true],
            'hydration_attribution' => ['array_keys_validation_control' => true],
            'batch_execute' => ['pdo_prepared_loop' => true],
        ]);

        $result = (new BenchmarkComparisonGate())->evaluate($report, $report);

        self::assertSame([], $result['blocking']);
        self::assertSame(
            [
                'batch_execute/pdo_prepared_loop',
                'hydration_attribution/array_keys_validation_control',
                'pdo_control_1000/pdo',
            ],
            $result['controls'],
        );
    }

    public function testSingleOrderSignalIsRetainedWithoutBlocking(): void
    {
        $baseline = $this->report(['hydration' => ['simplequery_associative' => true]]);
        $candidate = $this->report(['hydration' => ['simplequery_associative' => false]]);

        $result = (new BenchmarkComparisonGate())->evaluate($baseline, $candidate);

        self::assertSame([], $result['blocking']);
        self::assertSame([], $result['controls']);
        self::assertSame(['hydration/simplequery_associative'], $result['signals']);
    }

    public function testMixedSignalsArePartitioned(): void
    {
        $baseline = $this->report([
            'hydration' => ['simplequery_associative' => true],
            'pdo_control_1000' => ['pdo' => true],
            'observer' => ['observer_off' => true],
        ]);
        $candidate = $this->report([
            'hydration' => ['simplequery_associative' => true],
            'pdo_control_1000' => ['pdo' => true],
            'observer' => ['observer_off' => false],
        ]);

        $result = (new BenchmarkComparisonGate())->evaluate($baseline, $candidate);

        self::assertSame(['hydration/simplequery_associative'], $result['blocking']);
        self::assertSame(['pdo_control_1000/pdo'], $result['controls']);
        self::assertSame(
            ['hydration/simplequery_associative', 'observer/observer_off', 'pdo_control_1000/pdo'],
            $result['signals'],
        );
    }

    /**
     * @param array<string, array<string, bool>> $measurements
     * @return array<mixed>
     */
    private function report(array $measurements): array
    {
        $review = [];
        foreach ($measurements as $scenario => $operations) {
            foreach ($operations as $operation => $required) {
                $review[$scenario][$operation] = ['review_required' => $required];
            }
        }

        return ['performance_review' => ['measurements' => $review]];
    }
}
