<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class BenchmarkMedians
{
    /**
     * @param array<string, mixed> $run
     * @return array<string, array<string, float>>
     */
    public static function fromRun(array $run): array
    {
        $scenarios = $run['scenarios'] ?? null;
        if (!is_array($scenarios)) {
            throw new RuntimeException('Comparison run has no scenarios.');
        }

        $medians = [];
        foreach ($scenarios as $scenario) {
            [$name, $scenarioMedians] = self::parseScenario($scenario);
            $medians[$name] = $scenarioMedians;
        }

        return $medians;
    }

    /** @return array{string, array<string, float>} */
    private static function parseScenario(mixed $scenario): array
    {
        if (!is_array($scenario) || !is_string($scenario['scenario'] ?? null)) {
            throw new RuntimeException('Comparison run contains an invalid scenario.');
        }

        $operations = is_array($scenario['measurement'] ?? null)
            ? ($scenario['measurement']['operations'] ?? null)
            : null;
        if (!is_array($operations)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }

        $scenarioMedians = [];
        foreach ($operations as $opName => $operation) {
            if (!is_string($opName)) {
                throw new RuntimeException('Comparison operation has no median.');
            }
            $scenarioMedians[$opName] = self::extractMedian($operation);
        }

        return [$scenario['scenario'], $scenarioMedians];
    }

    private static function extractMedian(mixed $operation): float
    {
        $median = is_array($operation) ? ($operation['median_ms'] ?? null) : null;
        if (!is_int($median) && !is_float($median)) {
            throw new RuntimeException('Comparison operation has no median.');
        }

        return (float) $median;
    }
}
