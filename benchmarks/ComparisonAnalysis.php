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
        if (
            !is_finite($thresholdPercent)
            || !is_finite($absoluteNoiseFloorMs)
            || !is_finite($subMillisecondCeilingMs)
            || $thresholdPercent < 0.0
            || $absoluteNoiseFloorMs < 0.0
            || $subMillisecondCeilingMs <= 0.0
        ) {
            throw new RuntimeException('Benchmark comparison thresholds must be non-negative and finite.');
        }
        $baselineMedians = self::medians($baseline);
        $candidateMedians = self::medians($candidate);
        if (array_keys($baselineMedians) !== array_keys($candidateMedians)) {
            throw new RuntimeException('Baseline and candidate benchmark scenarios differ.');
        }

        $measurements = [];
        foreach ($baselineMedians as $scenario => $operations) {
            if (array_keys($operations) !== array_keys($candidateMedians[$scenario])) {
                throw new RuntimeException(sprintf('Benchmark operations differ for scenario "%s".', $scenario));
            }
            foreach ($operations as $operation => $baselineMedian) {
                $candidateMedian = $candidateMedians[$scenario][$operation];
                $change = self::percentageChange($baselineMedian, $candidateMedian);
                $absoluteChange = round($candidateMedian - $baselineMedian, 6);
                $relativeIgnored = max($baselineMedian, $candidateMedian) < $subMillisecondCeilingMs
                    && abs($absoluteChange) <= $absoluteNoiseFloorMs;
                $sameSourceRange = $sameSourceRanges[$scenario][$operation] ?? null;
                if ($sameSourceRange !== null && (!is_finite($sameSourceRange) || $sameSourceRange < 0.0)) {
                    throw new RuntimeException(sprintf(
                        'Invalid same-source range for %s/%s.',
                        $scenario,
                        $operation,
                    ));
                }
                $withinSameSourceRange = $sameSourceRange !== null
                    && abs($absoluteChange) <= $sameSourceRange;
                $relativeRegression = $change === null || $change > $thresholdPercent;
                $measurements[$scenario][$operation] = [
                    'baseline_median_ms' => $baselineMedian,
                    'candidate_median_ms' => $candidateMedian,
                    'absolute_change_ms' => $absoluteChange,
                    'change_percent' => $change,
                    'relative_change_ignored' => $relativeIgnored,
                    'same_source_range_ms' => $sameSourceRange,
                    'within_same_source_range' => $withinSameSourceRange,
                    'review_required' => $relativeRegression
                        && !$relativeIgnored
                        && !$withinSameSourceRange,
                ];
            }
        }

        return [
            'threshold_percent' => $thresholdPercent,
            'absolute_noise_floor_ms' => $absoluteNoiseFloorMs,
            'sub_millisecond_ceiling_ms' => $subMillisecondCeilingMs,
            'same_source_ranges_applied' => $sameSourceRanges !== [],
            'measurements' => $measurements,
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
            self::addScenarioMedians($medians, $scenario);
        }

        return $medians;
    }

    /** @param array<string, array<string, float>> $medians */
    private static function addScenarioMedians(array &$medians, mixed $scenario): void
    {
        $definition = self::scenarioDefinition($scenario);
        foreach ($definition['operations'] as $name => $operation) {
            $medians[$definition['name']][self::operationName($name)] = self::operationMedian($operation);
        }
    }

    /** @return array{name: string, operations: array<mixed, mixed>} */
    private static function scenarioDefinition(mixed $scenario): array
    {
        $scenario = self::scenarioArray($scenario);

        return [
            'name' => self::scenarioName($scenario['scenario'] ?? null),
            'operations' => self::scenarioOperations($scenario['measurement'] ?? null),
        ];
    }

    /** @return array<mixed, mixed> */
    private static function scenarioArray(mixed $scenario): array
    {
        if (!is_array($scenario)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }

        return $scenario;
    }

    private static function scenarioName(mixed $name): string
    {
        if (!is_string($name)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }

        return $name;
    }

    /** @return array<mixed, mixed> */
    private static function scenarioOperations(mixed $measurement): array
    {
        $measurement = self::scenarioMeasurement($measurement);

        return self::operationMap($measurement['operations'] ?? null);
    }

    /** @return array<mixed, mixed> */
    private static function scenarioMeasurement(mixed $measurement): array
    {
        if (!is_array($measurement)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }

        return $measurement;
    }

    /** @return array<mixed, mixed> */
    private static function operationMap(mixed $operations): array
    {
        if (!is_array($operations)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }

        return $operations;
    }

    private static function operationName(mixed $name): string
    {
        if (!is_string($name)) {
            throw new RuntimeException('Comparison operation has no median.');
        }

        return $name;
    }

    private static function operationMedian(mixed $operation): float
    {
        if (!is_array($operation)) {
            throw new RuntimeException('Comparison operation has no median.');
        }
        $median = $operation['median_ms'] ?? null;
        if (is_int($median)) {
            return (float) $median;
        }
        if (is_float($median)) {
            return $median;
        }

        throw new RuntimeException('Comparison operation has no median.');
    }

    private static function percentageChange(float $baseline, float $candidate): ?float
    {
        if ($baseline === 0.0) {
            return $candidate === 0.0 ? 0.0 : null;
        }

        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }
}
