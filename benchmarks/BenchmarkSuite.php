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
                ScenarioName::COMPILER_IN_LIST_SMALL,
                ScenarioName::COMPILER_IN_LIST_NORMAL,
                ScenarioName::COMPILER_IN_LIST_HIGH,
                ScenarioName::COMPILER_BATCH_INSERT_SMALL,
                ScenarioName::COMPILER_BATCH_INSERT_NORMAL,
                ScenarioName::COMPILER_BATCH_INSERT_HIGH,
                ScenarioName::COMPILER_ATTRIBUTION,
                ScenarioName::HYDRATION,
                ScenarioName::HYDRATION_WIDE,
                ScenarioName::FIRST_ROW,
                ScenarioName::FIRST_ROW_WIDE,
                ScenarioName::CURSOR_EXHAUSTION,
                ScenarioName::CURSOR_EXHAUSTION_WIDE,
                ScenarioName::CURSOR_EARLY_CLOSE,
                ScenarioName::CURSOR_EARLY_CLOSE_WIDE,
                ScenarioName::HYDRATION_ATTRIBUTION,
                ScenarioName::RESULT_MEMORY_SMALL,
                ScenarioName::RESULT_MEMORY_NORMAL,
                ScenarioName::RESULT_MEMORY_HIGH,
                ScenarioName::READ_TERMINALS,
                ScenarioName::TERMINAL_REUSE,
                ScenarioName::OBSERVER,
                ScenarioName::OBSERVER_WIDE,
                ScenarioName::EXECUTION_CLEANUP,
                ScenarioName::BATCH_EXECUTE,
                ScenarioName::TRANSACTIONS,
                ScenarioName::LIFECYCLE,
            ],
        };
    }
}
