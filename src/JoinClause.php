<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ComparisonOperator;
use Oeltima\SimpleQuery\Internal\Ast\ComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionIdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\IdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\RawPredicate;
use Oeltima\SimpleQuery\Internal\InputNormalizer;

final class JoinClause
{
    private readonly ConditionCollection $conditions;

    /** @internal */
    public function __construct()
    {
        $this->conditions = new ConditionCollection();
    }

    public function on(
        RawExpression|string|Identifier $left,
        mixed $operator = null,
        mixed $right = null,
    ): self {
        return $this->addIdentifierCondition(false, func_num_args(), $left, $operator, $right);
    }

    public function orOn(
        RawExpression|string|Identifier $left,
        mixed $operator = null,
        mixed $right = null,
    ): self {
        return $this->addIdentifierCondition(true, func_num_args(), $left, $operator, $right);
    }

    public function onValue(
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        return $this->addValueCondition(false, $expression, $operator, $value);
    }

    public function orOnValue(
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        return $this->addValueCondition(true, $expression, $operator, $value);
    }

    public function where(
        RawExpression|string|Identifier $expression,
        mixed $operatorOrValue,
        mixed $value = null,
    ): self {
        $argumentCount = func_num_args();
        $operator = $argumentCount === 2 ? '=' : $operatorOrValue;
        $comparisonValue = $argumentCount === 2 ? $operatorOrValue : $value;
        if (!is_string($operator)) {
            throw new InvalidQueryException('Join value operator must be a string.');
        }

        return $this->addValueCondition(false, $expression, $operator, $comparisonValue);
    }

    /** @internal */
    public function snapshot(): ConditionCollection
    {
        if ($this->conditions->isEmpty()) {
            throw new InvalidQueryException('A join condition cannot be empty.');
        }

        return $this->conditions->copy();
    }

    private function addIdentifierCondition(
        bool $or,
        int $argumentCount,
        RawExpression|string|Identifier $left,
        mixed $operator,
        mixed $right,
    ): self {
        if ($left instanceof RawExpression) {
            if ($argumentCount === 1) {
                $predicate = new RawPredicate($left);
                $this->conditions->add(new ConditionTerm($predicate, $or));

                return $this;
            }
        }

        if (
            $argumentCount !== 3
            || !is_string($operator)
            || (!is_string($right) && !$right instanceof Identifier && !$right instanceof RawExpression)
        ) {
            throw new InvalidQueryException(
                'Join on() requires expression/identifier, operator, and expression/identifier operands.',
            );
        }

        if (!$left instanceof RawExpression && !$right instanceof RawExpression) {
            $predicate = new IdentifierComparisonPredicate(
                InputNormalizer::identifier($left),
                ComparisonOperator::normalize($operator),
                InputNormalizer::identifier($right),
            );
        } elseif ($left instanceof RawExpression && $right instanceof RawExpression) {
            throw new InvalidQueryException('A structured join comparison accepts only one trusted raw expression.');
        } else {
            $predicate = new ExpressionIdentifierComparisonPredicate(
                $left instanceof RawExpression ? $left : InputNormalizer::identifier($left),
                ComparisonOperator::normalize($operator),
                $right instanceof RawExpression ? $right : InputNormalizer::identifier($right),
            );
        }
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addValueCondition(
        bool $or,
        RawExpression|string|Identifier $expression,
        string $operator,
        mixed $value,
    ): self {
        $binding = Binding::fromValue($value);
        if ($binding->type === ParameterType::Null) {
            throw new InvalidQueryException('Use a trusted raw condition for join null semantics.');
        }
        $normalizedOperator = ComparisonOperator::normalize($operator);
        $predicate = $expression instanceof RawExpression
            ? new ExpressionComparisonPredicate($expression, $normalizedOperator, $binding)
            : new ComparisonPredicate(InputNormalizer::identifier($expression), $normalizedOperator, $binding);
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }
}
