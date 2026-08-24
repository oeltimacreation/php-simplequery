<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class ComparisonAnalysis
{
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @param array<string, array<string, float>> $sameSourceRanges
     * @return array{
     *     threshold_percent: float,
     *     absolute_noise_floor_ms: float,
     *     sub_millisecond_ceiling_ms: float,
     *     same_source_ranges_applied: bool,
     *     measurements: array<string, array<string, array<string, float|bool|null>>>
     * }
     */
    public static function between(
        array $baseline,
        array $candidate,
        float $thresholdPercent = 5.0,
        float $absoluteNoiseFloorMs = 0.0,
        float $subMillisecondCeilingMs = 1.0,
        array $sameSourceRanges = [],
    ): array {
        $thresholds = new ComparisonThresholds(
            $thresholdPercent,
            $absoluteNoiseFloorMs,
            $subMillisecondCeilingMs,
        );

        $baselineMedians = self::medians($baseline);
        $candidateMedians = self::medians($candidate);

        return [
            'threshold_percent' => $thresholdPercent,
            'absolute_noise_floor_ms' => $absoluteNoiseFloorMs,
            'sub_millisecond_ceiling_ms' => $subMillisecondCeilingMs,
            'same_source_ranges_applied' => $sameSourceRanges !== [],
            'measurements' => self::compareRuns(
                $baselineMedians,
                $candidateMedians,
                $thresholds,
                $sameSourceRanges,
            ),
        ];
    }

    /**
     * @param array<string, array<string, float>> $baselineMedians
     * @param array<string, array<string, float>> $candidateMedians
     * @param array<string, array<string, float>> $sameSourceRanges
     * @return array<string, array<string, array<string, float|bool|null>>>
     */
    private static function compareRuns(
        array $baselineMedians,
        array $candidateMedians,
        ComparisonThresholds $thresholds,
        array $sameSourceRanges,
    ): array {
        if (array_keys($baselineMedians) !== array_keys($candidateMedians)) {
            throw new RuntimeException('Baseline and candidate benchmark scenarios differ.');
        }

        $measurements = [];
        foreach ($baselineMedians as $scenario => $operations) {
            $candidate = $candidateMedians[$scenario] ?? null;
            if ($candidate === null || array_keys($operations) !== array_keys($candidate)) {
                throw new RuntimeException(sprintf('Benchmark operations differ for scenario "%s".', $scenario));
            }
            $measurements[$scenario] = self::compareOperations(
                $operations,
                $candidate,
                $thresholds,
                $sameSourceRanges[$scenario] ?? [],
            );
        }

        return $measurements;
    }

    /**
     * @param array<string, float> $operations
     * @param array<string, float> $candidateOperations
     * @param array<string, float> $ranges
     * @return array<string, array<string, float|bool|null>>
     */
    private static function compareOperations(
        array $operations,
        array $candidateOperations,
        ComparisonThresholds $thresholds,
        array $ranges,
    ): array {
        $measurements = [];
        foreach ($operations as $operation => $baselineMedian) {
            $candidateMedian = $candidateOperations[$operation];
            $sameSourceRange = self::assertValidRange($ranges[$operation] ?? null, $operation);
            $measurements[$operation] = self::evaluateMeasurement(
                $baselineMedian,
                $candidateMedian,
                $thresholds,
                $sameSourceRange,
            );
        }

        return $measurements;
    }

    private static function assertValidRange(?float $range, string $operation): ?float
    {
        if ($range === null) {
            return null;
        }

        if (!is_finite($range) || $range < 0.0) {
            throw new RuntimeException(sprintf('Invalid same-source range for operation "%s".', $operation));
        }

        return $range;
    }

    /**
     * @return array{
     *     baseline_median_ms: float,
     *     candidate_median_ms: float,
     *     absolute_change_ms: float,
     *     change_percent: float|null,
     *     relative_change_ignored: bool,
     *     same_source_range_ms: float|null,
     *     within_same_source_range: bool,
     *     review_required: bool
     * }
     */
    private static function evaluateMeasurement(
        float $baselineMedian,
        float $candidateMedian,
        ComparisonThresholds $thresholds,
        ?float $sameSourceRange,
    ): array {
        $change = self::percentageChange($baselineMedian, $candidateMedian);
        $absoluteChange = round($candidateMedian - $baselineMedian, 6);
        $relativeIgnored = $thresholds->isRelativeChangeIgnored(
            $baselineMedian,
            $candidateMedian,
            $absoluteChange,
        );
        $withinRange = $sameSourceRange !== null && abs($absoluteChange) <= $sameSourceRange;
        $reviewRequired = $thresholds->isReviewRequired($change, $relativeIgnored, $withinRange);

        return [
            'baseline_median_ms' => $baselineMedian,
            'candidate_median_ms' => $candidateMedian,
            'absolute_change_ms' => $absoluteChange,
            'change_percent' => $change,
            'relative_change_ignored' => $relativeIgnored,
            'same_source_range_ms' => $sameSourceRange,
            'within_same_source_range' => $withinRange,
            'review_required' => $reviewRequired,
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, array<string, float>>
     */
    public static function medians(array $run): array
    {
        return BenchmarkMedians::fromRun($run);
    }

    private static function percentageChange(float $baseline, float $candidate): ?float
    {
        if ($baseline === 0.0) {
            return $candidate === 0.0 ? 0.0 : null;
        }

        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }
}
