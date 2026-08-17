<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

/**
 * @phpstan-type Scenario array{
 *     operations: array<non-empty-string, \Closure(): mixed>,
 *     pdo: \PDO|null,
 *     dimensions: array<string, int>
 * }
 */
final class ScenarioCatalog
{
    /** @return list<string> */
    public static function suite(BenchmarkSuite $suite): array
    {
        return $suite->scenarios();
    }

    /** @return Scenario */
    public static function prepare(ScenarioRequest $request): array
    {
        foreach (
            [
                ControlScenarios::class,
                CompilerScenarios::class,
                HydrationScenarios::class,
                ObserverScenarios::class,
                DatabaseScenarios::class,
                SoakScenarios::class,
            ] as $factoryClass
        ) {
            $prepared = (new $factoryClass())->prepare($request);
            if ($prepared !== null) {
                return $prepared;
            }
        }

        throw new RuntimeException(sprintf('Unknown benchmark scenario "%s".', $request->name->value()));
    }
}
