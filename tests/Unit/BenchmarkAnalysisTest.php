<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Benchmark\ComparisonAnalysis;
use Oeltima\SimpleQuery\Benchmark\NoiseAnalysis;
use PHPUnit\Framework\TestCase;

final class BenchmarkAnalysisTest extends TestCase
{
    public function testComparisonIgnoresSubMillisecondRelativeNoiseWithinTheAbsoluteFloor(): void
    {
        $review = ComparisonAnalysis::between(
            $this->benchmarkRun(['compile' => 0.100]),
            $this->benchmarkRun(['compile' => 0.108]),
            absoluteNoiseFloorMs: 0.010,
        );

        $measurement = $review['measurements']['scenario']['compile'];
        self::assertSame(8.0, $measurement['change_percent']);
        self::assertSame(0.008, $measurement['absolute_change_ms']);
        self::assertTrue($measurement['relative_change_ignored']);
        self::assertFalse($measurement['review_required']);
    }

    public function testComparisonReviewsARegressionOutsideTheAbsoluteFloor(): void
    {
        $review = ComparisonAnalysis::between(
            $this->benchmarkRun(['compile' => 0.100]),
            $this->benchmarkRun(['compile' => 0.112]),
            absoluteNoiseFloorMs: 0.010,
        );

        $measurement = $review['measurements']['scenario']['compile'];
        self::assertFalse($measurement['relative_change_ignored']);
        self::assertTrue($measurement['review_required']);
    }

    public function testNoiseFloorUsesTheLargestIdenticalSourceSubMillisecondMedianRange(): void
    {
        $analysis = NoiseAnalysis::repeatedIdenticalSource([
            $this->benchmarkRun(['small' => 0.100, 'large' => 1.100]),
            $this->benchmarkRun(['small' => 0.108, 'large' => 1.300]),
            $this->benchmarkRun(['small' => 0.103, 'large' => 1.200]),
        ]);

        self::assertSame(0.008, $analysis['absolute_noise_floor_ms']);
        self::assertTrue($analysis['measurements']['scenario']['small']['included_in_floor']);
        self::assertFalse($analysis['measurements']['scenario']['large']['included_in_floor']);
    }

    public function testComparisonRetainsButExplainsARegressionWithinTheSameSourceRange(): void
    {
        $review = ComparisonAnalysis::between(
            $this->benchmarkRun(['cursor' => 1.500]),
            $this->benchmarkRun(['cursor' => 1.590]),
            sameSourceRanges: ['scenario' => ['cursor' => 0.100]],
        );

        $measurement = $review['measurements']['scenario']['cursor'];
        self::assertSame(6.0, $measurement['change_percent']);
        self::assertFalse($measurement['relative_change_ignored']);
        self::assertTrue($measurement['within_same_source_range']);
        self::assertFalse($measurement['review_required']);
    }

    public function testComparisonReviewsARegressionOutsideTheSameSourceRange(): void
    {
        $review = ComparisonAnalysis::between(
            $this->benchmarkRun(['cursor' => 1.500]),
            $this->benchmarkRun(['cursor' => 1.610]),
            sameSourceRanges: ['scenario' => ['cursor' => 0.100]],
        );

        $measurement = $review['measurements']['scenario']['cursor'];
        self::assertFalse($measurement['within_same_source_range']);
        self::assertTrue($measurement['review_required']);
    }

    /**
     * @param array<string, float> $medians
     * @return array<string, mixed>
     */
    private function benchmarkRun(array $medians): array
    {
        $operations = [];
        foreach ($medians as $operation => $median) {
            $operations[$operation] = ['median_ms' => $median];
        }

        return [
            'scenarios' => [[
                'scenario' => 'scenario',
                'measurement' => ['operations' => $operations],
            ]],
        ];
    }
}
