<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;

/** @internal */
final class InputNormalizer
{
    /**
     * @param array<mixed> $bindings
     * @return list<Binding>
     */
    public static function rawBindings(array $bindings, string $invalidListMessage): array
    {
        if (!array_is_list($bindings)) {
            throw new InvalidQueryException($invalidListMessage);
        }

        return array_map(Binding::fromValue(...), $bindings);
    }

    public static function identifier(string|Identifier $identifier): Identifier
    {
        return is_string($identifier) ? Identifier::of($identifier) : $identifier;
    }

    public static function column(string|Identifier $column): Identifier
    {
        $identifier = self::identifier($column);
        if ($identifier->wildcard || $identifier->alias !== null) {
            throw new InvalidQueryException('Column identifiers cannot be wildcards or aliases.');
        }

        return $identifier;
    }

    public static function projection(string|Identifier|RawExpression $expression): Identifier|RawExpression
    {
        if (!is_string($expression)) {
            return $expression;
        }

        if ($expression === '*') {
            return Identifier::wildcard();
        }

        if (str_ends_with($expression, '.*')) {
            return Identifier::wildcard(substr($expression, 0, -2));
        }

        return Identifier::of($expression);
    }

    public static function structuredExpression(
        string|Identifier|RawExpression $expression,
    ): Identifier|RawExpression {
        return $expression instanceof RawExpression ? $expression : self::column($expression);
    }
}
