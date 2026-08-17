<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class ComparisonAnalysis
{
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @return array{
     *     threshold_percent: float,
     *     measurements: array<string, array<string, array<string, float|bool|null>>>
     * }
     */
    public static function between(array $baseline, array $candidate, float $thresholdPercent = 5.0): array
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
                    'review_required' => $change === null || $change > $thresholdPercent,
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
