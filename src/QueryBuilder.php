<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Closure;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ConditionFactory;
use Oeltima\SimpleQuery\Internal\Ast\ConditionTerm;
use Oeltima\SimpleQuery\Internal\Ast\JoinState;
use Oeltima\SimpleQuery\Internal\Ast\OrderClause;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Internal\AggregateResult;
use Oeltima\SimpleQuery\Internal\BuildsConditions;
use Oeltima\SimpleQuery\Internal\Compiler\CompilerFactory;
use Oeltima\SimpleQuery\Internal\Compiler\DialectCompiler;
use Oeltima\SimpleQuery\Internal\Executor;
use Oeltima\SimpleQuery\Internal\InputNormalizer;
use stdClass;

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
        return $this->compiler()->select($this->state);
    }

    /** @return list<stdClass> */
    public function get(): array
    {
        return $this->executor()->getObjects($this->compiledForExecution());
    }

    public function first(): ?stdClass
    {
        return $this->executor()->firstObject($this->compiledForExecution(true));
    }

    /** @return list<array<string, mixed>> */
    public function getAssociative(): array
    {
        return $this->executor()->getAssociative($this->compiledForExecution());
    }

    /** @return array<string, mixed>|null */
    public function firstAssociative(): ?array
    {
        return $this->executor()->firstAssociative($this->compiledForExecution(true));
    }

    public function iterate(): Cursor
    {
        return $this->executor()->cursor($this->compiledForExecution(), false);
    }

    public function iterateAssociative(): Cursor
    {
        return $this->executor()->cursor($this->compiledForExecution(), true);
    }

    public function count(): int
    {
        $query = $this->compiler()->count($this->state);
        $value = $this->executor()->scalar($query);
        try {
            return AggregateResult::count($value);
        } catch (\UnexpectedValueException $exception) {
            throw $this->invalidAggregate($exception->getMessage(), $query);
        }
    }

    public function sum(string|Identifier|RawExpression $column): int|float|string|null
    {
        return $this->numericAggregate('SUM', $column);
    }

    public function average(string|Identifier|RawExpression $column): int|float|string|null
    {
        return $this->numericAggregate('AVG', $column);
    }

    public function min(string|Identifier|RawExpression $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string|Identifier|RawExpression $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->executor()->affectedRows($this->compiler()->insert($this->state, $row));
    }

    /** @param array<string, mixed> $row */
    public function insertGetId(array $row): string
    {
        return $this->executor()->insertGetId($this->compiler()->insert($this->state, $row));
    }

    /** @param array<array-key, array<string, mixed>> $rows */
    public function insertMany(array $rows): int
    {
        return $this->executor()->affectedRows($this->compiler()->insertMany($this->state, $rows));
    }

    /** @param array<string, mixed> $changes */
    public function update(array $changes): int
    {
        return $this->executor()->affectedRows($this->compiler()->update($this->state, $changes));
    }

    public function delete(): int
    {
        return $this->executor()->affectedRows($this->compiler()->delete($this->state));
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

    private function compiler(): DialectCompiler
    {
        return CompilerFactory::for($this->connection->driver());
    }

    private function executor(): Executor
    {
        return new Executor($this->connection);
    }

    private function compiledForExecution(bool $first = false): CompiledQuery
    {
        $state = $this->state;
        if ($first) {
            $state = $this->state->copy();
            $state->limit = min($state->limit ?? 1, 1);
        }

        $query = $this->compiler()->select($state);
        if ($state->lock->mode !== null) {
            $this->connection->requireTransactionForLock();
        }

        return $query;
    }

    private function aggregate(
        string $function,
        string|Identifier|RawExpression $column,
    ): mixed {
        $query = $this->compiler()->aggregate(
            $this->state,
            $function,
            InputNormalizer::structuredExpression($column),
        );

        return $this->executor()->scalar($query);
    }

    private function numericAggregate(
        string $function,
        string|Identifier|RawExpression $column,
    ): int|float|string|null {
        $query = $this->compiler()->aggregate(
            $this->state,
            $function,
            InputNormalizer::structuredExpression($column),
        );
        $value = $this->executor()->scalar($query);
        if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
            throw $this->invalidAggregate('A numeric aggregate returned an unsupported scalar type.', $query);
        }

        return $value;
    }

    private function invalidAggregate(string $message, CompiledQuery $query): QueryExecutionException
    {
        return QueryExecutionException::invalidResult(
            $message,
            $query->sql,
            $this->connection->driver(),
            $this->connection->connectionOptions()->label,
        );
    }
}
