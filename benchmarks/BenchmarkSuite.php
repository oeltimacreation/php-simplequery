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
    case Ci = 'ci';
    case Full = 'full';

    /** @return list<ScenarioName> */
    public function scenarios(): array
    {
        return match ($this) {
            self::Baseline => [
                ScenarioName::PdoControl10,
                ScenarioName::PdoControl100,
                ScenarioName::PdoControl1000,
                ScenarioName::PdoControl5000,
                ScenarioName::CompilerPredicates10,
                ScenarioName::CompilerPredicates100,
                ScenarioName::CompilerPredicates1000,
                ScenarioName::MigrationQuery,
            ],
            self::Compiler => [
                ScenarioName::CompilerShapes,
                ScenarioName::CompilerPredicates10,
                ScenarioName::CompilerPredicates100,
                ScenarioName::CompilerPredicates1000,
                ScenarioName::BatchCompile,
            ],
            self::Executor => [
                ScenarioName::Hydration,
                ScenarioName::CursorExhaustion,
                ScenarioName::CursorEarlyClose,
                ScenarioName::ReadTerminals,
                ScenarioName::Observer,
                ScenarioName::BatchExecute,
                ScenarioName::Transactions,
                ScenarioName::Lifecycle,
                ScenarioName::MigrationQuery,
            ],
            self::Migration => [ScenarioName::MigrationQuery],
            self::HydrationExperiment => [
                ScenarioName::HydrationPdoAssociative,
                ScenarioName::HydrationSimpleQueryAssociative,
                ScenarioName::HydrationSimpleQueryObject,
                ScenarioName::CursorSimpleQueryAssociative,
                ScenarioName::CursorSimpleQueryObject,
            ],
            self::ObserverProfile => [
                ScenarioName::ObserverBindings1,
                ScenarioName::ObserverBindings10,
                ScenarioName::ObserverBindings50,
            ],
            self::Soak => [ScenarioName::CompilerRepeated, ScenarioName::LifecycleSoak],
            self::Ci, self::Full => self::maintainedScenarios(),
        };
    }

    /** @return list<ScenarioName> */
    private static function maintainedScenarios(): array
    {
        return [
            ScenarioName::PdoControl10,
            ScenarioName::PdoControl100,
            ScenarioName::PdoControl1000,
            ScenarioName::PdoControl5000,
            ScenarioName::CompilerPredicates10,
            ScenarioName::CompilerPredicates100,
            ScenarioName::CompilerPredicates1000,
            ScenarioName::MigrationQuery,
            ScenarioName::CompilerShapes,
            ScenarioName::BatchCompile,
            ScenarioName::Hydration,
            ScenarioName::CursorExhaustion,
            ScenarioName::CursorEarlyClose,
            ScenarioName::ReadTerminals,
            ScenarioName::Observer,
            ScenarioName::BatchExecute,
            ScenarioName::Transactions,
            ScenarioName::Lifecycle,
        ];
    }
}
