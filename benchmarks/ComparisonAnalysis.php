<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class ComparisonAnalysis
{
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @return array{threshold_percent: float, measurements: array<string, array<string, array<string, float|bool>>>}
     */
    public static function between(array $baseline, array $candidate, float $thresholdPercent = 10.0): array
    {
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
                $measurements[$scenario][$operation] = [
                    'baseline_median_ms' => $baselineMedian,
                    'candidate_median_ms' => $candidateMedian,
                    'change_percent' => $change,
                    'review_required' => $change > $thresholdPercent,
                ];
            }
        }

        return ['threshold_percent' => $thresholdPercent, 'measurements' => $measurements];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, array<string, float>>
     */
    private static function medians(array $run): array
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
        if (!is_array($scenario) || !is_string($scenario['scenario'] ?? null)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }
        $measurement = $scenario['measurement'] ?? null;
        $operations = is_array($measurement) ? ($measurement['operations'] ?? null) : null;
        if (!is_array($operations)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }
        foreach ($operations as $name => $operationMeasurement) {
            $median = is_array($operationMeasurement) ? ($operationMeasurement['median_ms'] ?? null) : null;
            if (!is_string($name) || (!is_int($median) && !is_float($median))) {
                throw new RuntimeException('Comparison operation has no median.');
            }
            $medians[$scenario['scenario']][$name] = (float) $median;
        }
    }

    private static function percentageChange(float $baseline, float $candidate): float
    {
        if ($baseline === 0.0) {
            return $candidate === 0.0 ? 0.0 : INF;
        }

        return round((($candidate - $baseline) / $baseline) * 100, 3);
    }
}
