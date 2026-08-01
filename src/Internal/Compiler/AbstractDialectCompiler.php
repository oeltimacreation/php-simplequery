<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\BetweenPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ConditionCollection;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionIdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\ExpressionNullPredicate;
use Oeltima\SimpleQuery\Internal\Ast\GroupPredicate;
use Oeltima\SimpleQuery\Internal\Ast\IdentifierComparisonPredicate;
use Oeltima\SimpleQuery\Internal\Ast\InPredicate;
use Oeltima\SimpleQuery\Internal\Ast\NullPredicate;
use Oeltima\SimpleQuery\Internal\Ast\Predicate;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;
use Oeltima\SimpleQuery\Internal\Ast\RawPredicate;
use Oeltima\SimpleQuery\Internal\Ast\Source;

/** @internal */
abstract class AbstractDialectCompiler implements DialectCompiler
{
    #[\Override]
    final public function select(QueryState $state): CompiledQuery
    {
        if ($state->offset !== null && $state->limit === null) {
            throw new InvalidQueryException('An offset requires a limit.');
        }

        $context = new CompilationContext();
        $sql = $this->selectClause($state, $context)
            . $this->joinClause($state, $context)
            . $this->whereClause($state, $context)
            . $this->groupByClause($state, $context)
            . $this->havingClause($state, $context)
            . $this->orderByClause($state, $context)
            . $this->paginationClause($state)
            . $this->lock($state);

        return new CompiledQuery($sql, $context->bindings());
    }

    private function selectClause(QueryState $state, CompilationContext $context): string
    {
        $projection = $state->projections === [] ? [Identifier::wildcard()] : $state->projections;
        $projectionSql = [];
        foreach ($projection as $expression) {
            $projectionSql[] = $this->expression($expression, $context);
        }

        return 'SELECT ' . ($state->distinct ? 'DISTINCT ' : '') . implode(', ', $projectionSql)
            . ' FROM ' . $this->source($state->source, $context);
    }

    private function joinClause(QueryState $state, CompilationContext $context): string
    {
        $sql = '';
        foreach ($state->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s',
                $join->type->value,
                $this->source($join->source, $context),
                $this->conditions($join->conditions, $context),
            );
        }

        return $sql;
    }

    private function whereClause(QueryState $state, CompilationContext $context): string
    {
        return $state->where->isEmpty() ? '' : ' WHERE ' . $this->conditions($state->where, $context);
    }

    private function groupByClause(QueryState $state, CompilationContext $context): string
    {
        if ($state->groups === []) {
            return '';
        }

        $groups = [];
        foreach ($state->groups as $group) {
            $groups[] = $this->expression($group, $context);
        }

        return ' GROUP BY ' . implode(', ', $groups);
    }

    private function havingClause(QueryState $state, CompilationContext $context): string
    {
        return $state->having->isEmpty() ? '' : ' HAVING ' . $this->conditions($state->having, $context);
    }

    private function orderByClause(QueryState $state, CompilationContext $context): string
    {
        if ($state->orders === []) {
            return '';
        }

        $orders = [];
        foreach ($state->orders as $order) {
            $orders[] = $this->expression($order->expression, $context) . ' ' . $order->direction->value;
        }

        return ' ORDER BY ' . implode(', ', $orders);
    }

    private function paginationClause(QueryState $state): string
    {
        $sql = '';
        if ($state->limit !== null) {
            $sql .= ' LIMIT ' . $state->limit;
        }
        if ($state->offset !== null) {
            $sql .= ' OFFSET ' . $state->offset;
        }

        return $sql;
    }

    #[\Override]
    final public function count(QueryState $state): CompiledQuery
    {
        $logicalState = $this->withoutTopLevelPaginationAndLock($state);
        if ($logicalState->distinct || $logicalState->groups !== [] || !$logicalState->having->isEmpty()) {
            $inner = $this->select($logicalState);

            return new CompiledQuery(
                sprintf(
                    'SELECT COUNT(*) FROM (%s) AS %s',
                    $inner->sql,
                    $this->quote(Identifier::fromSegments('simplequery_count')),
                ),
                $inner->bindings,
            );
        }

        $logicalState->projections = [new RawExpression('COUNT(*)')];

        return $this->select($logicalState);
    }

    #[\Override]
    final public function aggregate(
        QueryState $state,
        string $function,
        Identifier|RawExpression $column,
    ): CompiledQuery {
        $this->validateAggregateFunction($function);
        $this->validateAggregateColumn($column);
        $this->validateScalarAggregateShape($state);

        $expressionContext = new CompilationContext();
        $columnSql = $this->expression($column, $expressionContext);
        $aggregateState = $this->withoutTopLevelPaginationAndLock($state);
        $aggregateState->projections = [new RawExpression(
            sprintf('%s(%s)', $function, $columnSql),
            $expressionContext->bindings(),
        )];

        return $this->select($aggregateState);
    }

    private function validateAggregateFunction(string $function): void
    {
        if (!in_array($function, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new InvalidQueryException('Unknown aggregate function.');
        }
    }

    private function validateAggregateColumn(Identifier|RawExpression $column): void
    {
        if (!$column instanceof Identifier) {
            return;
        }
        if ($column->wildcard || $column->alias !== null) {
            throw new InvalidQueryException('Aggregate columns cannot be wildcards or aliases.');
        }
    }

    private function validateScalarAggregateShape(QueryState $state): void
    {
        if (!$state->distinct && $state->groups === [] && $state->having->isEmpty()) {
            return;
        }

        throw new UnsupportedFeatureException(
            'Non-count scalar aggregates do not support distinct, grouping, or HAVING clauses.',
        );
    }

    #[\Override]
    final public function insert(QueryState $state, array $row): CompiledQuery
    {
        $this->validateInsertState($state);
        if ($row === []) {
            throw new InvalidQueryException('An insert row cannot be empty.');
        }

        $context = new CompilationContext();
        $columns = $this->compileWriteColumns(array_keys($row));
        $values = $this->compileWriteValues($row, $context);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->physicalTable($state->source),
            implode(', ', $columns),
            implode(', ', $values),
        );

        return new CompiledQuery($sql, $context->bindings());
    }

    #[\Override]
    final public function insertMany(QueryState $state, array $rows): CompiledQuery
    {
        $this->validateInsertState($state);
        if ($rows === [] || !array_is_list($rows)) {
            throw new InvalidQueryException('A batch insert requires a non-empty list of rows.');
        }

        $firstColumns = array_keys($rows[0]);
        if ($firstColumns === []) {
            throw new InvalidQueryException('Batch insert rows cannot be empty.');
        }

        $context = new CompilationContext();
        $compiledColumns = $this->compileWriteColumns($firstColumns);

        $valueGroups = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== $firstColumns) {
                throw new InvalidQueryException('Every batch insert row must have identical ordered columns.');
            }
            $valueGroups[] = '(' . implode(', ', $this->compileWriteValues($row, $context)) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->physicalTable($state->source),
            implode(', ', $compiledColumns),
            implode(', ', $valueGroups),
        );

        return new CompiledQuery($sql, $context->bindings());
    }

    #[\Override]
    final public function update(QueryState $state, array $changes): CompiledQuery
    {
        $this->validateUpdateDeleteState($state);
        if ($changes === []) {
            throw new InvalidQueryException('An update change set cannot be empty.');
        }

        $context = new CompilationContext();
        $assignments = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $this->writeColumn($column) . ' = ' . $this->writeValue($value, $context);
        }

        $sql = sprintf('UPDATE %s SET %s', $this->physicalTable($state->source), implode(', ', $assignments));
        if (!$state->where->isEmpty()) {
            $sql .= ' WHERE ' . $this->conditions($state->where, $context);
        }

        return new CompiledQuery($sql, $context->bindings());
    }

    #[\Override]
    final public function delete(QueryState $state): CompiledQuery
    {
        $this->validateUpdateDeleteState($state);
        $context = new CompilationContext();
        $sql = 'DELETE FROM ' . $this->physicalTable($state->source);
        if (!$state->where->isEmpty()) {
            $sql .= ' WHERE ' . $this->conditions($state->where, $context);
        }

        return new CompiledQuery($sql, $context->bindings());
    }

    abstract protected function quoteCharacter(): string;

    abstract protected function lock(QueryState $state): string;

    protected function quote(Identifier $identifier): string
    {
        $quote = $this->quoteCharacter();
        if ($identifier->wildcard && $identifier->segments === ['*']) {
            return '*';
        }

        $segments = array_map(
            static fn (string $segment): string => $quote . str_replace($quote, $quote . $quote, $segment) . $quote,
            $identifier->segments,
        );
        $sql = implode('.', $segments) . ($identifier->wildcard ? '.*' : '');
        if ($identifier->alias !== null) {
            $sql .= ' AS ' . $quote . str_replace($quote, $quote . $quote, $identifier->alias) . $quote;
        }

        return $sql;
    }

    protected function validateLockShape(QueryState $state): void
    {
        if ($state->lock->mode === null) {
            return;
        }

        if ($state->distinct || $state->groups !== [] || !$state->source->isPhysicalTable()) {
            throw new UnsupportedFeatureException('Locking distinct, grouped, or derived queries is unsupported.');
        }

        foreach ($state->projections as $projection) {
            if ($projection instanceof RawExpression) {
                throw new UnsupportedFeatureException(
                    'Locking raw or potentially aggregate projections is unsupported.',
                );
            }
        }
    }

    private function expression(Identifier|RawExpression $expression, CompilationContext $context): string
    {
        if ($expression instanceof Identifier) {
            return $this->quote($expression);
        }

        $context->bindAll($expression->bindings);

        return $expression->sql;
    }

    private function source(Source $source, CompilationContext $context): string
    {
        if ($source->table !== null) {
            $sql = $this->quote($source->table);
        } elseif ($source->subquery !== null) {
            $context->bindAll($source->subquery->bindings);
            $sql = '(' . $source->subquery->sql . ')';
        } else {
            throw new InvalidQueryException('Query source is invalid.');
        }

        if ($source->alias !== null) {
            $sql .= ' AS ' . $this->quote(Identifier::fromSegments($source->alias));
        }

        return $sql;
    }

    private function conditions(ConditionCollection $conditions, CompilationContext $context): string
    {
        $parts = [];
        foreach ($conditions->terms() as $index => $term) {
            $sql = $this->predicate($term->predicate, $context);
            if ($term->negated) {
                $sql = 'NOT (' . $sql . ')';
            }
            $parts[] = ($index === 0 ? '' : ($term->or ? 'OR ' : 'AND ')) . $sql;
        }

        if ($parts === []) {
            throw new InvalidQueryException('A condition collection cannot be empty.');
        }

        return implode(' ', $parts);
    }

    private function predicate(Predicate $predicate, CompilationContext $context): string
    {
        $sql = $this->comparisonPredicate($predicate, $context);
        if ($sql !== null) {
            return $sql;
        }

        $sql = $this->simplePredicate($predicate, $context);
        if ($sql !== null) {
            return $sql;
        }

        return $this->compoundPredicate($predicate, $context);
    }

    private function comparisonPredicate(Predicate $predicate, CompilationContext $context): ?string
    {
        if ($predicate instanceof ComparisonPredicate) {
            $context->bind($predicate->value);

            return $this->quote($predicate->column) . ' ' . $predicate->operator->value . ' ?';
        }

        if ($predicate instanceof ExpressionComparisonPredicate) {
            $sql = $this->expression($predicate->expression, $context);
            $context->bind($predicate->value);

            return $sql . ' ' . $predicate->operator->value . ' ?';
        }

        if ($predicate instanceof IdentifierComparisonPredicate) {
            return sprintf(
                '%s %s %s',
                $this->quote($predicate->left),
                $predicate->operator->value,
                $this->quote($predicate->right),
            );
        }

        if ($predicate instanceof ExpressionIdentifierComparisonPredicate) {
            return sprintf(
                '%s %s %s',
                $this->expression($predicate->left, $context),
                $predicate->operator->value,
                $this->expression($predicate->right, $context),
            );
        }

        return null;
    }

    private function simplePredicate(Predicate $predicate, CompilationContext $context): ?string
    {
        if ($predicate instanceof NullPredicate) {
            return $this->quote($predicate->column) . ($predicate->negated ? ' IS NOT NULL' : ' IS NULL');
        }

        if ($predicate instanceof ExpressionNullPredicate) {
            return $this->expression($predicate->expression, $context)
                . ($predicate->negated ? ' IS NOT NULL' : ' IS NULL');
        }

        if ($predicate instanceof RawPredicate) {
            return $this->expression($predicate->expression, $context);
        }

        return null;
    }

    private function compoundPredicate(Predicate $predicate, CompilationContext $context): string
    {
        if ($predicate instanceof GroupPredicate) {
            return '(' . $this->conditions($predicate->conditions, $context) . ')';
        }

        if ($predicate instanceof InPredicate) {
            if (is_array($predicate->values)) {
                if ($predicate->values === []) {
                    return $predicate->negated ? '1 = 1' : '0 = 1';
                }
                $context->bindAll($predicate->values);
                $valuesSql = implode(', ', array_fill(0, count($predicate->values), '?'));
            } else {
                $context->bindAll($predicate->values->bindings);
                $valuesSql = $predicate->values->sql;
            }

            return sprintf(
                '%s %sIN (%s)',
                $this->quote($predicate->column),
                $predicate->negated ? 'NOT ' : '',
                $valuesSql,
            );
        }

        if ($predicate instanceof BetweenPredicate) {
            $context->bind($predicate->from);
            $context->bind($predicate->to);

            return $this->quote($predicate->column) . ' BETWEEN ? AND ?';
        }

        throw new InvalidQueryException('Unknown predicate type.');
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function compileWriteColumns(array $columns): array
    {
        $compiled = [];
        foreach ($columns as $column) {
            $compiled[] = $this->writeColumn($column);
        }

        return $compiled;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function compileWriteValues(array $row, CompilationContext $context): array
    {
        $values = [];
        foreach ($row as $value) {
            $values[] = $this->writeValue($value, $context);
        }

        return $values;
    }

    private function writeValue(mixed $value, CompilationContext $context): string
    {
        if ($value instanceof RawExpression) {
            $context->bindAll($value->bindings);

            return $value->sql;
        }

        $context->bind(Binding::fromValue($value));

        return '?';
    }

    private function writeColumn(string $column): string
    {
        $identifier = Identifier::of($column);
        if (count($identifier->segments) !== 1 || $identifier->alias !== null || $identifier->wildcard) {
            throw new InvalidQueryException('Write columns must be unqualified identifier names.');
        }

        return $this->quote($identifier);
    }

    private function physicalTable(Source $source): string
    {
        if ($source->table === null || $source->alias !== null || $source->table->alias !== null) {
            throw new UnsupportedFeatureException('A structured write requires an unaliased physical table.');
        }

        return $this->quote($source->table);
    }

    private function validateInsertState(QueryState $state): void
    {
        $this->validateWriteState($state, allowPredicates: false);
    }

    private function validateUpdateDeleteState(QueryState $state): void
    {
        $this->validateWriteState($state, allowPredicates: true);
    }

    private function validateWriteState(QueryState $state, bool $allowPredicates): void
    {
        $this->physicalTable($state->source);
        if (
            $state->projections !== []
            || $state->distinct
            || (!$allowPredicates && !$state->where->isEmpty())
            || $state->joins !== []
            || $state->groups !== []
            || !$state->having->isEmpty()
            || $state->orders !== []
            || $state->limit !== null
            || $state->offset !== null
            || $state->lock->mode !== null
        ) {
            throw new UnsupportedFeatureException(
                $allowPredicates
                    ? 'Update and delete accept predicates but no other read clauses.'
                    : 'Insert does not accept read clauses.',
            );
        }
    }

    private function withoutTopLevelPaginationAndLock(QueryState $state): QueryState
    {
        $copy = $state->copy();
        $copy->orders = [];
        $copy->limit = null;
        $copy->offset = null;
        $copy->lock->mode = null;
        $copy->lock->modifier = null;

        return $copy;
    }
}
