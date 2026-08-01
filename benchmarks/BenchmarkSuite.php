<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

enum BenchmarkSuite: string
{
    case Baseline = 'baseline';
    case Compiler = 'compiler';
    case Executor = 'executor';
    case Migration = 'migration';
    case HydrationExperiment = 'hydration-experiment';
    case ObserverProfile = 'observer-profile';
    case Soak = 'soak';
    case Production = 'production';
    case Ci = 'ci';
    case Full = 'full';

    /** @return list<string> */
    public function scenarios(): array
    {
        return match ($this) {
            self::Baseline => [
                ScenarioName::PDO_CONTROL_10,
                ScenarioName::PDO_CONTROL_100,
                ScenarioName::PDO_CONTROL_1000,
                ScenarioName::PDO_CONTROL_5000,
                ScenarioName::COMPILER_PREDICATES_10,
                ScenarioName::COMPILER_PREDICATES_100,
                ScenarioName::COMPILER_PREDICATES_1000,
                ScenarioName::MIGRATION_QUERY,
            ],
            self::Compiler => [
                ScenarioName::COMPILER_SHAPES,
                ScenarioName::COMPILER_PREDICATES_10,
                ScenarioName::COMPILER_PREDICATES_100,
                ScenarioName::COMPILER_PREDICATES_1000,
                ScenarioName::COMPILE_ALLOCATION,
                ScenarioName::BATCH_COMPILE,
            ],
            self::Executor => [
                ScenarioName::CURSOR_EXHAUSTION,
                ScenarioName::CURSOR_EARLY_CLOSE,
                ScenarioName::READ_TERMINALS,
                ScenarioName::TERMINAL_REUSE,
                ScenarioName::OBSERVER,
                ScenarioName::BATCH_EXECUTE,
                ScenarioName::TRANSACTIONS,
                ScenarioName::LIFECYCLE,
                ScenarioName::MIGRATION_QUERY,
            ],
            self::Migration => [ScenarioName::MIGRATION_QUERY],
            self::HydrationExperiment => [
                ScenarioName::HYDRATION_PDO_ASSOCIATIVE,
                ScenarioName::HYDRATION_SIMPLEQUERY_ASSOCIATIVE,
                ScenarioName::HYDRATION_SIMPLEQUERY_OBJECT,
                ScenarioName::CURSOR_SIMPLEQUERY_ASSOCIATIVE,
                ScenarioName::CURSOR_SIMPLEQUERY_OBJECT,
            ],
            self::ObserverProfile => [
                ScenarioName::OBSERVER_BINDINGS_1,
                ScenarioName::OBSERVER_BINDINGS_10,
                ScenarioName::OBSERVER_BINDINGS_50,
            ],
            self::Soak => [
                ScenarioName::COMPILER_REPEATED,
                ScenarioName::LIFECYCLE_SOAK,
                ScenarioName::STREAMING_CURSOR_SOAK,
                ScenarioName::BATCH_WRITE_SOAK,
            ],
            self::Production => self::productionScenarios(),
            self::Ci, self::Full => self::maintainedScenarios(),
        };
    }

    /** @return list<string> */
    private static function maintainedScenarios(): array
    {
        return [
            ScenarioName::PDO_CONTROL_10,
            ScenarioName::PDO_CONTROL_100,
            ScenarioName::PDO_CONTROL_1000,
            ScenarioName::PDO_CONTROL_5000,
            ScenarioName::COMPILER_PREDICATES_10,
            ScenarioName::COMPILER_PREDICATES_100,
            ScenarioName::COMPILER_PREDICATES_1000,
            ScenarioName::MIGRATION_QUERY,
            ScenarioName::COMPILER_SHAPES,
            ScenarioName::COMPILE_ALLOCATION,
            ScenarioName::BATCH_COMPILE,
            ScenarioName::CURSOR_EXHAUSTION,
            ScenarioName::CURSOR_EARLY_CLOSE,
            ScenarioName::READ_TERMINALS,
            ScenarioName::TERMINAL_REUSE,
            ScenarioName::OBSERVER,
            ScenarioName::BATCH_EXECUTE,
            ScenarioName::TRANSACTIONS,
            ScenarioName::LIFECYCLE,
            ...self::productionScenarios(),
        ];
    }

    /** @return list<string> */
    private static function productionScenarios(): array
    {
        return [
            ScenarioName::PRODUCTION_REPORT_COMPILE,
            ScenarioName::PRODUCTION_COUNT_COMPILE,
            ScenarioName::PRODUCTION_REPORT_EXECUTE,
            ScenarioName::PRODUCTION_BATCH_EXECUTE,
        ];
    }
}
