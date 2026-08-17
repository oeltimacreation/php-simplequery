<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final class ScenarioName
{
    public const PDO_CONTROL_10 = 'pdo_control_10';
    public const PDO_CONTROL_100 = 'pdo_control_100';
    public const PDO_CONTROL_1000 = 'pdo_control_1000';
    public const PDO_CONTROL_5000 = 'pdo_control_5000';
    public const COMPILER_PREDICATES_10 = 'compiler_predicates_10';
    public const COMPILER_PREDICATES_100 = 'compiler_predicates_100';
    public const COMPILER_PREDICATES_1000 = 'compiler_predicates_1000';
    public const COMPILER_SHAPES = 'compiler_shapes';
    public const COMPILER_REPEATED = 'compiler_repeated';
    public const COMPILE_ALLOCATION = 'compile_allocation';
    public const BATCH_COMPILE = 'batch_compile';
    public const TERMINAL_REUSE = 'terminal_reuse';
    public const HYDRATION = 'hydration';
    public const CURSOR_EXHAUSTION = 'cursor_exhaustion';
    public const CURSOR_EARLY_CLOSE = 'cursor_early_close';
    public const READ_TERMINALS = 'read_terminals';
    public const OBSERVER = 'observer';
    public const BATCH_EXECUTE = 'batch_execute';
    public const TRANSACTIONS = 'transactions';
    public const LIFECYCLE = 'lifecycle';
    public const LIFECYCLE_SOAK = 'lifecycle_soak';
    public const STREAMING_CURSOR_SOAK = 'streaming_cursor_soak';
    public const BATCH_WRITE_SOAK = 'batch_write_soak';

    private const VALUES = [
        self::PDO_CONTROL_10,
        self::PDO_CONTROL_100,
        self::PDO_CONTROL_1000,
        self::PDO_CONTROL_5000,
        self::COMPILER_PREDICATES_10,
        self::COMPILER_PREDICATES_100,
        self::COMPILER_PREDICATES_1000,
        self::COMPILER_SHAPES,
        self::COMPILER_REPEATED,
        self::COMPILE_ALLOCATION,
        self::BATCH_COMPILE,
        self::TERMINAL_REUSE,
        self::HYDRATION,
        self::CURSOR_EXHAUSTION,
        self::CURSOR_EARLY_CLOSE,
        self::READ_TERMINALS,
        self::OBSERVER,
        self::BATCH_EXECUTE,
        self::TRANSACTIONS,
        self::LIFECYCLE,
        self::LIFECYCLE_SOAK,
        self::STREAMING_CURSOR_SOAK,
        self::BATCH_WRITE_SOAK,
    ];

    private string $value;

    private function __construct()
    {
    }

    /** @param array{name: string} $input */
    public static function from(array $input): self
    {
        $name = $input['name'];
        if (!in_array($name, self::VALUES, true)) {
            throw new RuntimeException(sprintf('Unknown benchmark scenario "%s".', $name));
        }

        $scenario = new self();
        $scenario->value = $name;

        return $scenario;
    }

    public function dimension(): ?int
    {
        return match ($this->value) {
            self::PDO_CONTROL_10, self::COMPILER_PREDICATES_10 => 10,
            self::PDO_CONTROL_100, self::COMPILER_PREDICATES_100 => 100,
            self::PDO_CONTROL_1000, self::COMPILER_PREDICATES_1000 => 1_000,
            self::PDO_CONTROL_5000 => 5_000,
            default => null,
        };
    }

    public function value(): string
    {
        return $this->value;
    }
}
