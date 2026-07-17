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

    public function onValue(string|Identifier $column, string $operator, mixed $value): self
    {
        return $this->addValueCondition(false, $column, $operator, $value);
    }

    public function orOnValue(string|Identifier $column, string $operator, mixed $value): self
    {
        return $this->addValueCondition(true, $column, $operator, $value);
    }

    public function where(
        string|Identifier $column,
        mixed $operatorOrValue,
        mixed $value = null,
    ): self {
        $argumentCount = func_num_args();
        $operator = $argumentCount === 2 ? '=' : $operatorOrValue;
        $comparisonValue = $argumentCount === 2 ? $operatorOrValue : $value;
        if (!is_string($operator)) {
            throw new InvalidQueryException('Join value operator must be a string.');
        }

        return $this->addValueCondition(false, $column, $operator, $comparisonValue);
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
            if ($argumentCount !== 1) {
                throw new InvalidQueryException('A raw join condition does not accept additional arguments.');
            }
            $predicate = new RawPredicate($left);
        } else {
            if (
                $argumentCount !== 3
                || !is_string($operator)
                || (!is_string($right) && !$right instanceof Identifier)
            ) {
                throw new InvalidQueryException('Join on() requires left identifier, operator, and right identifier.');
            }
            $predicate = new IdentifierComparisonPredicate(
                InputNormalizer::identifier($left),
                ComparisonOperator::normalize($operator),
                InputNormalizer::identifier($right),
            );
        }
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addValueCondition(bool $or, string|Identifier $column, string $operator, mixed $value): self
    {
        $binding = Binding::fromValue($value);
        if ($binding->type === ParameterType::Null) {
            throw new InvalidQueryException('Use a trusted raw condition for join null semantics.');
        }
        $predicate = new ComparisonPredicate(
            InputNormalizer::identifier($column),
            ComparisonOperator::normalize($operator),
            $binding,
        );
        $this->conditions->add(new ConditionTerm($predicate, $or));

        return $this;
    }
}
