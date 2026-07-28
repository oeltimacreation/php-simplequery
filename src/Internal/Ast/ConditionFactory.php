<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Closure;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\InputNormalizer;
use Oeltima\SimpleQuery\QueryBuilder;

/** @internal */
final class ConditionFactory
{
    /** @param RawExpression|(Closure(ConditionGroup): mixed)|string|Identifier $subject */
    public static function condition(
        Connection $connection,
        int $argumentCount,
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue,
        mixed $value,
    ): Predicate {
        if ($subject instanceof RawExpression) {
            return self::rawCondition($argumentCount, $subject, $operatorOrValue, $value);
        }

        if ($subject instanceof Closure) {
            return self::groupCondition($connection, $argumentCount, $subject);
        }

        return self::identifierCondition($argumentCount, $subject, $operatorOrValue, $value);
    }

    private static function rawCondition(
        int $argumentCount,
        RawExpression $expression,
        mixed $operatorOrValue,
        mixed $value,
    ): Predicate {
        if ($argumentCount === 1) {
            return new RawPredicate($expression);
        }
        if ($argumentCount !== 2 && $argumentCount !== 3) {
            throw new InvalidQueryException(
                'An expression comparison requires an expression/value or expression/operator/value shape.',
            );
        }

        return self::comparison($argumentCount, $expression, $operatorOrValue, $value);
    }

    /** @param Closure(ConditionGroup): mixed $groupCallback */
    private static function groupCondition(
        Connection $connection,
        int $argumentCount,
        Closure $groupCallback,
    ): GroupPredicate {
        if ($argumentCount !== 1) {
            throw new InvalidQueryException('A condition group does not accept additional arguments.');
        }

        $group = new ConditionGroup($connection);
        $groupCallback($group);
        if ($group->isEmpty()) {
            throw new InvalidQueryException('A condition group cannot be empty.');
        }

        return new GroupPredicate($group->snapshot());
    }

    private static function identifierCondition(
        int $argumentCount,
        string|Identifier $column,
        mixed $operatorOrValue,
        mixed $value,
    ): Predicate {
        if ($argumentCount !== 2 && $argumentCount !== 3) {
            throw new InvalidQueryException('A comparison requires a column/value or column/operator/value shape.');
        }

        return self::comparison(
            $argumentCount,
            InputNormalizer::identifier($column),
            $operatorOrValue,
            $value,
        );
    }

    private static function comparison(
        int $argumentCount,
        RawExpression|Identifier $left,
        mixed $operatorOrValue,
        mixed $value,
    ): Predicate {
        $operator = $argumentCount === 2
            ? ComparisonOperator::Equal
            : self::operator($operatorOrValue);
        $comparisonValue = $argumentCount === 2 ? $operatorOrValue : $value;
        $binding = Binding::fromValue($comparisonValue);
        if ($binding->type->value === 'null') {
            return self::nullComparison($left, $operator);
        }

        return $left instanceof RawExpression
            ? new ExpressionComparisonPredicate($left, $operator, $binding)
            : new ComparisonPredicate($left, $operator, $binding);
    }

    private static function nullComparison(
        RawExpression|Identifier $left,
        ComparisonOperator $operator,
    ): Predicate {
        if (!$operator->supportsNull()) {
            throw new InvalidQueryException('Ordering comparisons against null are not supported.');
        }

        return $left instanceof RawExpression
            ? new ExpressionNullPredicate($left, !$operator->isEquality())
            : new NullPredicate($left, !$operator->isEquality());
    }

    public static function columns(
        string|Identifier $left,
        string $operator,
        string|Identifier $right,
    ): IdentifierComparisonPredicate {
        return new IdentifierComparisonPredicate(
            InputNormalizer::identifier($left),
            ComparisonOperator::normalize($operator),
            InputNormalizer::identifier($right),
        );
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    public static function in(
        Connection $connection,
        string|Identifier $column,
        iterable|QueryBuilder $values,
        bool $negated,
    ): InPredicate {
        $normalizedColumn = InputNormalizer::identifier($column);
        if ($values instanceof QueryBuilder) {
            if (!$values->belongsTo($connection)) {
                throw new InvalidQueryException('Structured subqueries must belong to the same connection.');
            }

            return new InPredicate($normalizedColumn, $values->compile(), $negated);
        }

        $bindings = [];
        foreach ($values as $value) {
            $bindings[] = Binding::fromValue($value);
        }

        return new InPredicate($normalizedColumn, $bindings, $negated);
    }

    public static function between(
        string|Identifier $column,
        mixed $from,
        mixed $to,
    ): BetweenPredicate {
        return new BetweenPredicate(
            InputNormalizer::identifier($column),
            Binding::fromValue($from),
            Binding::fromValue($to),
        );
    }

    private static function operator(mixed $operator): ComparisonOperator
    {
        if (!is_string($operator)) {
            throw new InvalidQueryException('Comparison operator must be a string.');
        }

        return ComparisonOperator::normalize($operator);
    }
}
