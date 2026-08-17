<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Closure;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\JoinState;
use Oeltima\SimpleQuery\Internal\Ast\JoinType;
use Oeltima\SimpleQuery\Internal\Ast\LockMode;
use Oeltima\SimpleQuery\Internal\Ast\LockModifier;
use Oeltima\SimpleQuery\Internal\Ast\OrderClause;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Internal\AggregateResult;
use Oeltima\SimpleQuery\Internal\BuildsConditions;
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

    /** @param (Closure(JoinClause): mixed)|RawExpression|string|Identifier $conditionOrLeft */
    public function join(
        string|Identifier $table,
        RawExpression|Closure|string|Identifier $conditionOrLeft,
        mixed $operator = self::MISSING,
        mixed $right = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addJoin(JoinType::Inner, $table, $conditionOrLeft, $operator, $right, $extra);
    }

    /** @param (Closure(JoinClause): mixed)|RawExpression|string|Identifier $conditionOrLeft */
    public function innerJoin(
        string|Identifier $table,
        RawExpression|Closure|string|Identifier $conditionOrLeft,
        mixed $operator = self::MISSING,
        mixed $right = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addJoin(JoinType::Inner, $table, $conditionOrLeft, $operator, $right, $extra);
    }

    /** @param (Closure(JoinClause): mixed)|RawExpression|string|Identifier $conditionOrLeft */
    public function leftJoin(
        string|Identifier $table,
        RawExpression|Closure|string|Identifier $conditionOrLeft,
        mixed $operator = self::MISSING,
        mixed $right = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addJoin(JoinType::Left, $table, $conditionOrLeft, $operator, $right, $extra);
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

    /** @param RawExpression|(Closure(ConditionGroup): mixed)|string|Identifier $subject */
    public function having(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = self::MISSING,
        mixed $value = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addCondition(false, false, $subject, $operatorOrValue, $value, $this->state->having, $extra);
    }

    /** @param RawExpression|(Closure(ConditionGroup): mixed)|string|Identifier $subject */
    public function orHaving(
        RawExpression|Closure|string|Identifier $subject,
        mixed $operatorOrValue = self::MISSING,
        mixed $value = self::MISSING,
        mixed ...$extra,
    ): self {
        return $this->addCondition(true, false, $subject, $operatorOrValue, $value, $this->state->having, $extra);
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

    public function when(mixed $value, Closure $callback): self
    {
        if ((bool) $value) {
            $callback($this);
        }

        return $this;
    }

    public function unless(mixed $value, Closure $callback): self
    {
        if (!(bool) $value) {
            $callback($this);
        }

        return $this;
    }

    public function forPage(int $page, int $perPage): self
    {
        if ($page < 1) {
            throw new InvalidQueryException('Page must be at least 1.');
        }
        if ($perPage < 1) {
            throw new InvalidQueryException('Page size must be at least 1.');
        }

        $pageOffset = $page - 1;
        if ($pageOffset > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidQueryException('Page offset exceeds the supported integer range.');
        }

        return $this->limit($perPage)->offset($pageOffset * $perPage);
    }

    public function forUpdate(): self
    {
        return $this->setLockMode(LockMode::Update);
    }

    public function forShare(): self
    {
        return $this->setLockMode(LockMode::Share);
    }

    public function noWait(): self
    {
        return $this->setLockModifier(LockModifier::NoWait);
    }

    public function skipLocked(): self
    {
        return $this->setLockModifier(LockModifier::SkipLocked);
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

    /** @return Cursor<stdClass> */
    public function iterate(): Cursor
    {
        return $this->executor()->objectCursor($this->compiledForExecution());
    }

    /** @return Cursor<array<string, mixed>> */
    public function iterateAssociative(): Cursor
    {
        return $this->executor()->associativeCursor($this->compiledForExecution());
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

    /** @param (Closure(JoinClause): mixed)|RawExpression|string|Identifier $conditionOrLeft
     * @param array<array-key, mixed> $extra
     */
    private function addJoin(
        JoinType $type,
        string|Identifier $table,
        RawExpression|Closure|string|Identifier $conditionOrLeft,
        mixed $operator,
        mixed $right,
        array $extra = [],
    ): self {
        $identifier = InputNormalizer::identifier($table);
        if ($identifier->wildcard) {
            throw new InvalidQueryException('A join source cannot be a wildcard.');
        }
        $source = Source::table($identifier->withoutAlias(), $identifier->alias);
        $clause = new JoinClause();
        $this->applyJoinCondition($clause, $conditionOrLeft, $operator, $right, $extra);

        $this->state->joins[] = new JoinState($type, $source, $clause->snapshot());

        return $this;
    }

    /** @param (Closure(JoinClause): mixed)|RawExpression|string|Identifier $conditionOrLeft
     * @param array<array-key, mixed> $extra
     */
    private function applyJoinCondition(
        JoinClause $clause,
        RawExpression|Closure|string|Identifier $conditionOrLeft,
        mixed $operator,
        mixed $right,
        array $extra,
    ): void {
        $supplied = $this->joinArgumentCount($operator, $right, $extra);
        if ($conditionOrLeft instanceof Closure) {
            if ($supplied !== 0) {
                throw new InvalidQueryException('A join closure does not accept additional arguments.');
            }
            $conditionOrLeft($clause);

            return;
        }

        if ($supplied !== 2) {
            throw new InvalidQueryException('A direct join requires table, left, operator, and right.');
        }
        $clause->on($conditionOrLeft, $operator, $right);
    }

    /** @param array<array-key, mixed> $extra */
    private function joinArgumentCount(mixed $operator, mixed $right, array $extra): int
    {
        $count = count($extra);
        if ($operator !== self::MISSING) {
            ++$count;
        }
        if ($right !== self::MISSING) {
            ++$count;
        }

        return $count;
    }

    private function setLockMode(LockMode $mode): self
    {
        if ($this->state->lock->mode !== null && $this->state->lock->mode !== $mode) {
            throw new InvalidQueryException('Exactly one row-lock mode can be selected.');
        }
        $this->state->lock->mode = $mode;

        return $this;
    }

    private function setLockModifier(LockModifier $modifier): self
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
        return $this->connection->compilerForQueryBuilding();
    }

    private function executor(): Executor
    {
        return $this->connection->executorForQueryBuilding();
    }

    private function compiledForExecution(bool $first = false): CompiledQuery
    {
        $state = $this->state;
        if ($first) {
            $state = $this->state->copyForCompilation();
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
        $query = $this->aggregateCompiled($function, $column);

        return $this->executor()->scalar($query);
    }

    private function numericAggregate(
        string $function,
        string|Identifier|RawExpression $column,
    ): int|float|string|null {
        $query = $this->aggregateCompiled($function, $column);
        $value = $this->executor()->scalar($query);
        if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
            throw $this->invalidAggregate('A numeric aggregate returned an unsupported scalar type.', $query);
        }

        return $value;
    }

    private function aggregateCompiled(string $function, string|Identifier|RawExpression $column): CompiledQuery
    {
        return $this->compiler()->aggregate(
            $this->state,
            $function,
            InputNormalizer::structuredExpression($column),
        );
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
