<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Closure;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ConditionFactory;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionNullPredicate;
use Oeltima\SimpleQuery\Internal\Ast\NullPredicate;
use Oeltima\SimpleQuery\QueryBuilder;

/** @internal */
trait BuildsConditions
{
    abstract protected function conditionConnection(): Connection;

    abstract protected function conditionCollection(): ConditionCollection;

    /** @param RawExpression|(Closure(\Oeltima\SimpleQuery\ConditionGroup): mixed)|string|Identifier $subject */
    public function where(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): static {
        return $this->addCondition(false, false, func_num_args(), $subject, $operatorOrValue, $value);
    }

    /** @param RawExpression|(Closure(\Oeltima\SimpleQuery\ConditionGroup): mixed)|string|Identifier $subject */
    public function orWhere(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): static {
        return $this->addCondition(true, false, func_num_args(), $subject, $operatorOrValue, $value);
    }

    /** @param RawExpression|(Closure(\Oeltima\SimpleQuery\ConditionGroup): mixed)|string|Identifier $subject */
    public function whereNot(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): static {
        return $this->addCondition(false, true, func_num_args(), $subject, $operatorOrValue, $value);
    }

    /** @param RawExpression|(Closure(\Oeltima\SimpleQuery\ConditionGroup): mixed)|string|Identifier $subject */
    public function orWhereNot(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): static {
        return $this->addCondition(true, true, func_num_args(), $subject, $operatorOrValue, $value);
    }

    public function whereColumn(
        string|Identifier $left,
        string $operator,
        string|Identifier $right,
    ): static {
        return $this->addColumnCondition(false, $left, $operator, $right);
    }

    public function orWhereColumn(
        string|Identifier $left,
        string $operator,
        string|Identifier $right,
    ): static {
        return $this->addColumnCondition(true, $left, $operator, $right);
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    public function whereIn(string|Identifier $column, iterable|QueryBuilder $values): static
    {
        return $this->addIn(false, false, $column, $values);
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    public function orWhereIn(string|Identifier $column, iterable|QueryBuilder $values): static
    {
        return $this->addIn(true, false, $column, $values);
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    public function whereNotIn(string|Identifier $column, iterable|QueryBuilder $values): static
    {
        return $this->addIn(false, true, $column, $values);
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    public function orWhereNotIn(string|Identifier $column, iterable|QueryBuilder $values): static
    {
        return $this->addIn(true, true, $column, $values);
    }

    public function whereBetween(string|Identifier $column, mixed $from, mixed $to): static
    {
        return $this->addBetween(false, $column, $from, $to);
    }

    public function orWhereBetween(string|Identifier $column, mixed $from, mixed $to): static
    {
        return $this->addBetween(true, $column, $from, $to);
    }

    public function whereNull(string|Identifier $column): static
    {
        return $this->addNull(false, false, $column);
    }

    public function orWhereNull(string|Identifier $column): static
    {
        return $this->addNull(true, false, $column);
    }

    public function whereNotNull(string|Identifier $column): static
    {
        return $this->addNull(false, true, $column);
    }

    public function orWhereNotNull(string|Identifier $column): static
    {
        return $this->addNull(true, true, $column);
    }

    /** @param RawExpression|(Closure(\Oeltima\SimpleQuery\ConditionGroup): mixed)|string|Identifier $subject */
    private function addCondition(
        bool $or,
        bool $negated,
        int $argumentCount,
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue,
        mixed $value,
    ): static {
        $predicate = ConditionFactory::condition(
            $this->conditionConnection(),
            $argumentCount,
            $subject,
            $operatorOrValue,
            $value,
        );

        if ($negated && $predicate instanceof NullPredicate) {
            $predicate = new NullPredicate($predicate->column, !$predicate->negated);
            $negated = false;
        }
        if ($negated && $predicate instanceof ExpressionNullPredicate) {
            $predicate = new ExpressionNullPredicate($predicate->expression, !$predicate->negated);
            $negated = false;
        }

        $this->conditionCollection()->add(new ConditionTerm($predicate, $or, $negated));

        return $this;
    }

    /** @param iterable<mixed>|QueryBuilder $values */
    private function addIn(
        bool $or,
        bool $negated,
        string|Identifier $column,
        iterable|QueryBuilder $values,
    ): static {
        $predicate = ConditionFactory::in($this->conditionConnection(), $column, $values, $negated);
        $this->conditionCollection()->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addColumnCondition(
        bool $or,
        string|Identifier $left,
        string $operator,
        string|Identifier $right,
    ): static {
        $predicate = ConditionFactory::columns($left, $operator, $right);
        $this->conditionCollection()->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addBetween(bool $or, string|Identifier $column, mixed $from, mixed $to): static
    {
        $predicate = ConditionFactory::between($column, $from, $to);
        $this->conditionCollection()->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function addNull(bool $or, bool $negated, string|Identifier $column): static
    {
        $predicate = new NullPredicate(InputNormalizer::identifier($column), $negated);
        $this->conditionCollection()->add(new ConditionTerm($predicate, $or));

        return $this;
    }
}
