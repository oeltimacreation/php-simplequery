<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

enum ScenarioName: string
{
    case PdoControl10 = 'pdo_control_10';
    case PdoControl100 = 'pdo_control_100';
    case PdoControl1000 = 'pdo_control_1000';
    case PdoControl5000 = 'pdo_control_5000';
    case CompilerPredicates10 = 'compiler_predicates_10';
    case CompilerPredicates100 = 'compiler_predicates_100';
    case CompilerPredicates1000 = 'compiler_predicates_1000';
    case CompilerShapes = 'compiler_shapes';
    case CompilerRepeated = 'compiler_repeated';
    case BatchCompile = 'batch_compile';
    case Hydration = 'hydration';
    case CursorExhaustion = 'cursor_exhaustion';
    case CursorEarlyClose = 'cursor_early_close';
    case ReadTerminals = 'read_terminals';
    case Observer = 'observer';
    case ObserverBindings1 = 'observer_bindings_1';
    case ObserverBindings10 = 'observer_bindings_10';
    case ObserverBindings50 = 'observer_bindings_50';
    case BatchExecute = 'batch_execute';
    case Transactions = 'transactions';
    case Lifecycle = 'lifecycle';
    case LifecycleSoak = 'lifecycle_soak';
    case MigrationQuery = 'migration_query';
    case HydrationPdoAssociative = 'hydration_pdo_associative';
    case HydrationSimpleQueryAssociative = 'hydration_simplequery_associative';
    case HydrationSimpleQueryObject = 'hydration_simplequery_object';
    case CursorSimpleQueryAssociative = 'cursor_simplequery_associative';
    case CursorSimpleQueryObject = 'cursor_simplequery_object';

    public function dimension(): ?int
    {
        return match ($this) {
            self::PdoControl10, self::CompilerPredicates10, self::ObserverBindings10 => 10,
            self::PdoControl100, self::CompilerPredicates100 => 100,
            self::PdoControl1000, self::CompilerPredicates1000 => 1_000,
            self::PdoControl5000 => 5_000,
            self::ObserverBindings1 => 1,
            self::ObserverBindings50 => 50,
            default => null,
        };
    }
}
