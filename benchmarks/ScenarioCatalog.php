<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class ScenarioCatalog
{
    /** @return list<string> */
    public static function suite(BenchmarkSuite $suite): array
    {
        return $suite->scenarios();
    }

    public static function prepare(ScenarioRequest $request): PreparedScenario
    {
        foreach (self::factories() as $factory) {
            $prepared = $factory->prepare($request);
            if ($prepared !== null) {
                return $prepared;
            }
        }

        throw new RuntimeException(sprintf('Unknown benchmark scenario "%s".', $request->name->value()));
    }

    /** @return list<ScenarioFactory> */
    private static function factories(): array
    {
        return [
            new ControlScenarios(),
            new CompilerScenarios(),
            new ProductionCompilerScenarios(),
            new HydrationScenarios(),
            new ObserverScenarios(),
            new DatabaseScenarios(),
            new ProductionWorkloadScenarios(),
        ];
    }
}
