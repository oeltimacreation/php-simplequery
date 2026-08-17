<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

enum BenchmarkSuite: string
{
    case Soak = 'soak';
    case Ci = 'ci';

    /** @return list<string> */
    public function scenarios(): array
    {
        return match ($this) {
            self::Soak => [
                ScenarioName::COMPILER_REPEATED,
                ScenarioName::LIFECYCLE_SOAK,
                ScenarioName::STREAMING_CURSOR_SOAK,
                ScenarioName::BATCH_WRITE_SOAK,
            ],
            self::Ci => [
                ScenarioName::PDO_CONTROL_10,
                ScenarioName::PDO_CONTROL_100,
                ScenarioName::PDO_CONTROL_1000,
                ScenarioName::PDO_CONTROL_5000,
                ScenarioName::COMPILER_PREDICATES_10,
                ScenarioName::COMPILER_PREDICATES_100,
                ScenarioName::COMPILER_PREDICATES_1000,
                ScenarioName::COMPILER_SHAPES,
                ScenarioName::COMPILE_ALLOCATION,
                ScenarioName::BATCH_COMPILE,
                ScenarioName::HYDRATION,
                ScenarioName::CURSOR_EXHAUSTION,
                ScenarioName::CURSOR_EARLY_CLOSE,
                ScenarioName::READ_TERMINALS,
                ScenarioName::TERMINAL_REUSE,
                ScenarioName::OBSERVER,
                ScenarioName::BATCH_EXECUTE,
                ScenarioName::TRANSACTIONS,
                ScenarioName::LIFECYCLE,
            ],
        };
    }
}
