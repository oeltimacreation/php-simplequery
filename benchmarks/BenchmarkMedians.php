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

        $operations = self::extractOperations($scenario);
        $scenarioMedians = [];
        foreach ($operations as $opName => $operation) {
            $scenarioMedians[$opName] = self::extractMedian($operation);
        }

        return [$scenario['scenario'], $scenarioMedians];
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    private static function extractOperations(array $scenario): array
    {
        $measurement = $scenario['measurement'] ?? null;
        $operations = is_array($measurement) ? ($measurement['operations'] ?? null) : null;
        if (!is_array($operations)) {
            throw new RuntimeException('Comparison scenario has no operation measurements.');
        }

        /** @var array<string, mixed> $operations */
        return $operations;
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
