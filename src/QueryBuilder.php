<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Closure;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ConditionFactory;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\JoinState;
use Oeltima\SimpleQuery\Internal\Ast\OrderClause;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Internal\BuildsConditions;
use Oeltima\SimpleQuery\Internal\Compiler\CompilerFactory;
use Oeltima\SimpleQuery\Internal\InputNormalizer;

final class QueryBuilder
{
    use BuildsConditions;

    private QueryState $state;

    /** @internal */
    public function __construct(
        private readonly Connection $connection,
        Source $source,
    ) {
        $this->state = new QueryState($source);
    }

    public function select(string|Identifier|RawExpression ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('select() requires at least one projection.');
        }

        foreach ($columns as $column) {
            $this->state->projections[] = InputNormalizer::projection($column);
        }

        return $this;
    }

    public function distinct(): self
    {
        $this->state->distinct = true;

        return $this;
    }

    public function as(string $alias): self
    {
        $source = $this->state->source;
        if ($source->alias !== null && $source->alias !== $alias) {
            throw new InvalidQueryException('Conflicting source aliases were supplied.');
        }

        $this->state->source = $source->table !== null
            ? Source::table($source->table, $alias)
            : Source::subquery($source->subquery ?? throw new InvalidQueryException('Invalid source.'), $alias);

        return $this;
    }

    public function join(
        string|Identifier $table,
        Closure|string|Identifier $conditionOrLeft,
        mixed $operator = null,
        mixed $right = null,
    ): self {
        return $this->addJoin('INNER', func_num_args(), $table, $conditionOrLeft, $operator, $right);
    }

    public function innerJoin(
        string|Identifier $table,
        Closure|string|Identifier $conditionOrLeft,
        mixed $operator = null,
        mixed $right = null,
    ): self {
        return $this->addJoin('INNER', func_num_args(), $table, $conditionOrLeft, $operator, $right);
    }

    public function leftJoin(
        string|Identifier $table,
        Closure|string|Identifier $conditionOrLeft,
        mixed $operator = null,
        mixed $right = null,
    ): self {
        return $this->addJoin('LEFT', func_num_args(), $table, $conditionOrLeft, $operator, $right);
    }

    public function groupBy(string|Identifier|RawExpression ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('groupBy() requires at least one expression.');
        }

        foreach ($columns as $column) {
            $this->state->groups[] = InputNormalizer::structuredExpression($column);
        }

        return $this;
    }

    public function having(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): self {
        return $this->addHaving(false, func_num_args(), $subject, $operatorOrValue, $value);
    }

    public function orHaving(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = null,
        mixed $value = null,
    ): self {
        return $this->addHaving(true, func_num_args(), $subject, $operatorOrValue, $value);
    }

    public function orderBy(
        string|Identifier|RawExpression $column,
        SortDirection|string $direction = SortDirection::Asc,
    ): self {
        $normalizedDirection = is_string($direction) ? SortDirection::fromString($direction) : $direction;
        $this->state->orders[] = new OrderClause(
            InputNormalizer::structuredExpression($column),
            $normalizedDirection,
        );

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidQueryException('Limit cannot be negative.');
        }
        $this->state->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException('Offset cannot be negative.');
        }
        $this->state->offset = $offset;

        return $this;
    }

    public function forUpdate(): self
    {
        return $this->setLockMode('update');
    }

    public function forShare(): self
    {
        return $this->setLockMode('share');
    }

    public function noWait(): self
    {
        return $this->setLockModifier('NOWAIT');
    }

    public function skipLocked(): self
    {
        return $this->setLockModifier('SKIP LOCKED');
    }

    public function compile(): CompiledQuery
    {
        return CompilerFactory::for($this->connection->driver())->select($this->state);
    }

    /** @internal */
    public function snapshotForCompilation(): QueryState
    {
        return $this->state->copy();
    }

    /** @internal */
    public function belongsTo(Connection $connection): bool
    {
        return $this->connection === $connection;
    }

    /** @internal */
    public function driverForCompilation(): Driver
    {
        return $this->connection->driver();
    }

    public function __clone()
    {
        $this->state = $this->state->copy();
    }

    #[\Override]
    protected function conditionConnection(): Connection
    {
        return $this->connection;
    }

    #[\Override]
    protected function conditionCollection(): ConditionCollection
    {
        return $this->state->where;
    }

    private function addJoin(
        string $type,
        int $argumentCount,
        string|Identifier $table,
        Closure|string|Identifier $conditionOrLeft,
        mixed $operator,
        mixed $right,
    ): self {
        $identifier = InputNormalizer::identifier($table);
        if ($identifier->wildcard) {
            throw new InvalidQueryException('A join source cannot be a wildcard.');
        }
        $source = Source::table($identifier->withoutAlias(), $identifier->alias);
        $clause = new JoinClause();
        if ($conditionOrLeft instanceof Closure) {
            if ($argumentCount !== 2) {
                throw new InvalidQueryException('A join closure does not accept additional arguments.');
            }
            $conditionOrLeft($clause);
        } else {
            if ($argumentCount !== 4) {
                throw new InvalidQueryException('A direct join requires table, left, operator, and right.');
            }
            $clause->on($conditionOrLeft, $operator, $right);
        }

        $this->state->joins[] = new JoinState($type, $source, $clause->snapshot());

        return $this;
    }

    private function addHaving(
        bool $or,
        int $argumentCount,
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue,
        mixed $value,
    ): self {
        $predicate = ConditionFactory::condition(
            $this->connection,
            $argumentCount,
            $subject,
            $operatorOrValue,
            $value,
        );
        $this->state->having->add(new ConditionTerm($predicate, $or));

        return $this;
    }

    private function setLockMode(string $mode): self
    {
        if ($this->state->lock->mode !== null && $this->state->lock->mode !== $mode) {
            throw new InvalidQueryException('Exactly one row-lock mode can be selected.');
        }
        $this->state->lock->mode = $mode;

        return $this;
    }

    private function setLockModifier(string $modifier): self
    {
        if ($this->state->lock->mode === null) {
            throw new InvalidQueryException('A lock modifier requires a row-lock mode.');
        }
        if ($this->state->lock->modifier !== null && $this->state->lock->modifier !== $modifier) {
            throw new InvalidQueryException('NOWAIT and SKIP LOCKED are mutually exclusive.');
        }
        $this->state->lock->modifier = $modifier;

        return $this;
    }
}
