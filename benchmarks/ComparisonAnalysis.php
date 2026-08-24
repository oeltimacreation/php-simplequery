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
        if (array_keys($baselineMedians) !== array_keys($candidateMedians)) {
            throw new RuntimeException('Baseline and candidate benchmark scenarios differ.');
        }

        return [
            'threshold_percent' => $thresholdPercent,
            'absolute_noise_floor_ms' => $absoluteNoiseFloorMs,
            'sub_millisecond_ceiling_ms' => $subMillisecondCeilingMs,
            'same_source_ranges_applied' => $sameSourceRanges !== [],
            'measurements' => self::compareScenarios(
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
    private static function compareScenarios(
        array $baselineMedians,
        array $candidateMedians,
        ComparisonThresholds $thresholds,
        array $sameSourceRanges,
    ): array {
        $measurements = [];
        foreach ($baselineMedians as $scenario => $operations) {
            $candidateOperations = $candidateMedians[$scenario] ?? null;
            if ($candidateOperations === null || array_keys($operations) !== array_keys($candidateOperations)) {
                throw new RuntimeException(sprintf('Benchmark operations differ for scenario "%s".', $scenario));
            }
            $measurements[$scenario] = self::compareScenarioOperations(
                $scenario,
                $operations,
                $candidateOperations,
                $thresholds,
                $sameSourceRanges[$scenario] ?? [],
            );
        }

        return $measurements;
    }

    /**
     * @param array<string, float> $operations
     * @param array<string, float> $candidateOperations
     * @param array<string, float> $scenarioRanges
     * @return array<string, array<string, float|bool|null>>
     */
    private static function compareScenarioOperations(
        string $scenario,
        array $operations,
        array $candidateOperations,
        ComparisonThresholds $thresholds,
        array $scenarioRanges,
    ): array {
        $measurements = [];
        foreach ($operations as $operation => $baselineMedian) {
            $candidateMedian = $candidateOperations[$operation];
            $sameSourceRange = $scenarioRanges[$operation] ?? null;
            if ($sameSourceRange !== null && (!is_finite($sameSourceRange) || $sameSourceRange < 0.0)) {
                throw new RuntimeException(sprintf('Invalid same-source range for %s/%s.', $scenario, $operation));
            }
            $measurements[$operation] = self::evaluateMeasurement(
                $baselineMedian,
                $candidateMedian,
                $thresholds,
                $sameSourceRange,
            );
        }

        return $measurements;
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
        $scenarios = $run['scenarios'] ?? null;
        if (!is_array($scenarios)) {
            throw new RuntimeException('Comparison run has no scenarios.');
        }

        $medians = [];
        foreach ($scenarios as $scenario) {
            [$name, $scenarioMedians] = self::parseScenarioMedians($scenario);
            $medians[$name] = $scenarioMedians;
        }

        return $medians;
    }

    /** @return array{string, array<string, float>} */
    private static function parseScenarioMedians(mixed $scenario): array
    {
        if (!is_array($scenario) || !is_string($scenario['scenario'] ?? null)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }

        $measurement = is_array($scenario['measurement'] ?? null) ? $scenario['measurement'] : null;
        $operations = $measurement['operations'] ?? null;
        if (!is_array($operations)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }

        $scenarioMedians = [];
        foreach ($operations as $opName => $operation) {
            $scenarioMedians[self::assertOperationName($opName)] = self::extractMedian($operation);
        }

        return [$scenario['scenario'], $scenarioMedians];
    }

    private static function assertOperationName(mixed $opName): string
    {
        if (!is_string($opName)) {
            throw new RuntimeException('Comparison operation has no median.');
        }

        return $opName;
    }

    private static function extractMedian(mixed $operation): float
    {
        $median = is_array($operation) ? ($operation['median_ms'] ?? null) : null;
        if (!is_int($median) && !is_float($median)) {
            throw new RuntimeException('Comparison operation has no median.');
        }

        return (float) $median;
    }

    private static function percentageChange(float $baseline, float $candidate): ?float
    {
        if ($baseline === 0.0) {
            return $candidate === 0.0 ? 0.0 : null;
        }

        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }
}
