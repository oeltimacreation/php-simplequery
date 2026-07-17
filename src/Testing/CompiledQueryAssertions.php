<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Testing;

use LogicException;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;

final class CompiledQueryAssertions
{
    /**
     * @param list<mixed> $expectedValues
     * @param list<\Oeltima\SimpleQuery\ParameterType> $expectedTypes
     */
    public static function assertMatches(
        CompiledQuery $query,
        string $expectedSql,
        array $expectedValues = [],
        array $expectedTypes = [],
    ): void {
        $actualValues = array_map(static fn (Binding $binding): mixed => $binding->value, $query->bindings);
        $actualTypes = array_map(static fn (Binding $binding) => $binding->type, $query->bindings);

        if ($query->sql !== $expectedSql) {
            throw new LogicException(sprintf(
                "Compiled SQL differs.\nExpected: %s\nActual:   %s",
                $expectedSql,
                $query->sql,
            ));
        }
        if ($actualValues !== $expectedValues) {
            throw new LogicException('Compiled binding values differ.');
        }
        if ($expectedTypes !== [] && $actualTypes !== $expectedTypes) {
            throw new LogicException('Compiled binding types differ.');
        }
    }
}
