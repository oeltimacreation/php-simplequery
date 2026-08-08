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

    /**
     * @return list<ScenarioFactory>
     */
    private static function factories(): array
    {
        $factories = [];
        foreach (self::factoryClasses() as $factory) {
            if (class_exists($factory)) {
                $factories[] = new $factory();
            }
        }

        return $factories;
    }

    /**
     * Factories are guarded by class existence so the runner can compare against
     * older source autoloaders (for example `v0.3.0` before Phase 4 added
     * `SoakScenarios`) without requiring the baseline tree to contain classes it
     * does not have.
     *
     * @return list<class-string<ScenarioFactory>>
     */
    private static function factoryClasses(): array
    {
        return [
            ControlScenarios::class,
            CompilerScenarios::class,
            ProductionCompilerScenarios::class,
            HydrationScenarios::class,
            ObserverScenarios::class,
            DatabaseScenarios::class,
            ProductionWorkloadScenarios::class,
            SoakScenarios::class,
        ];
    }
}
