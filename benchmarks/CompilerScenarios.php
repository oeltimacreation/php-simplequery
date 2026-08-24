<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use LogicException;
use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class CompilerScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::COMPILER_PREDICATES_10,
            ScenarioName::COMPILER_PREDICATES_100,
            ScenarioName::COMPILER_PREDICATES_1000 => $this->predicates($request),
            ScenarioName::COMPILER_SHAPES => $this->shapes($request),
            ScenarioName::COMPILER_REPEATED => $this->repeated($request),
            ScenarioName::COMPILE_ALLOCATION => $this->allocation($request),
            ScenarioName::COMPILER_IN_LIST_SMALL,
            ScenarioName::COMPILER_IN_LIST_NORMAL,
            ScenarioName::COMPILER_IN_LIST_HIGH => $this->inList($request),
            ScenarioName::COMPILER_BATCH_INSERT_SMALL,
            ScenarioName::COMPILER_BATCH_INSERT_NORMAL,
            ScenarioName::COMPILER_BATCH_INSERT_HIGH => $this->batch($request),
            ScenarioName::COMPILER_ATTRIBUTION => $this->attribution($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function predicates(ScenarioRequest $request): array
    {
        $predicates = $request->name->dimension() ?? throw new \LogicException('Missing predicate dimension.');
        $connection = CompilerConnection::for(Driver::Sqlite);
        $operation = static function () use ($connection, $predicates): array {
            $query = $connection->table('benchmark_rows')->select('id');
            for ($predicate = 0; $predicate < $predicates; ++$predicate) {
                $query->where('id', '>=', $predicate);
            }
            $compiled = $query->compile();

            return [
                'sql_hash' => hash('sha256', $compiled->sql),
                'binding_count' => count($compiled->bindings),
                'first_binding' => $compiled->bindings[0]->value ?? null,
                'last_binding' => $compiled->bindings[$predicates - 1]->value ?? null,
            ];
        };

        return [
            'operations' => ['simplequery' => $operation],
            'pdo' => null,
            'dimensions' => ['predicates' => $predicates],
        ];
    }

    /** @return Scenario */
    private function shapes(ScenarioRequest $request): array
    {
        $width = $request->scale(['ci' => 50, 'reference' => 250]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $prepared = $this->shapeBuilder($connection, $width);
        $buildAndCompile = fn (): array => $this->compiledSummary(
            $this->shapeBuilder($connection, $width)->compile(),
        );
        $compileOnly = fn (): array => $this->compiledSummary($prepared->compile());

        return [
            'operations' => [
                'build_and_compile' => $buildAndCompile,
                'prepared_builder_compile_only' => $compileOnly,
            ],
            'pdo' => null,
            'dimensions' => ['shape_width' => $width],
        ];
    }

    private function shapeBuilder(Connection $connection, int $width): QueryBuilder
    {
        $subquery = $connection->table('roles')->select('user_id')->where('enabled', true);

        return $connection
            ->table('users', 'u')
            ->select('u.id', $connection->raw('COALESCE(u.score, ?) AS score', [0]))
            ->join('profiles', 'profiles.user_id', '=', 'u.id')
            ->where(static function (ConditionGroup $group) use ($width): void {
                for ($index = 0; $index < $width; ++$index) {
                    $group->orWhere('u.rank', '>=', $index);
                }
            })
            ->whereIn('u.id', range(1, $width))
            ->whereIn('u.id', $subquery)
            ->orderBy('u.id');
    }

    /** @return array{sql_hash: string, binding_count: int, first_binding: mixed, last_binding: mixed} */
    private function compiledSummary(CompiledQuery $compiled): array
    {
        return [
            'sql_hash' => hash('sha256', $compiled->sql),
            'binding_count' => count($compiled->bindings),
            'first_binding' => $compiled->bindings[0]->value ?? null,
            'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
        ];
    }

    /** @return Scenario */
    private function repeated(ScenarioRequest $request): array
    {
        $compiles = $request->scale(['ci' => 2_000, 'reference' => 20_000]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $query = $connection->table('events')->where('active', true)->whereIn('kind', ['a', 'b', 'c']);
        $operation = static function () use ($query, $compiles): array {
            $last = $query->compile();
            for ($index = 1; $index < $compiles; ++$index) {
                $last = $query->compile();
            }

            return ['compiles' => $compiles, 'sql_hash' => hash('sha256', $last->sql)];
        };

        return [
            'operations' => ['repeated_compile' => $operation],
            'pdo' => null,
            'dimensions' => ['compiles' => $compiles],
        ];
    }

    /** @return Scenario */
    private function allocation(ScenarioRequest $request): array
    {
        $compiles = $request->scale(['ci' => 2_000, 'reference' => 20_000]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $operation = static function () use ($connection, $compiles): array {
            $last = $connection
                ->table('events')
                ->where('active', true)
                ->whereIn('kind', ['a', 'b', 'c'])
                ->compile();
            for ($index = 1; $index < $compiles; ++$index) {
                $last = $connection
                    ->table('events')
                    ->where('active', true)
                    ->whereIn('kind', ['a', 'b', 'c'])
                    ->compile();
            }

            return ['compiles' => $compiles, 'sql_hash' => hash('sha256', $last->sql)];
        };

        return [
            'operations' => ['fresh_builder_compile' => $operation],
            'pdo' => null,
            'dimensions' => ['compiles' => $compiles],
        ];
    }

    /** @return Scenario */
    private function inList(ScenarioRequest $request): array
    {
        $values = $this->positiveDimension($request, 'list');
        $connection = CompilerConnection::for(Driver::Sqlite);
        $query = $connection->table('benchmark_rows')->select('id')->whereIn('id', range(1, $values));
        $reference = $query->compile();
        $summary = $this->compiledSummary($reference);
        $operation = fn (): array => $this->compiledSummary($query->compile());
        $operations = ['prepared_builder_compile' => $operation];
        if ($request->name->value() === ScenarioName::COMPILER_IN_LIST_HIGH) {
            $operations['placeholder_array_control'] = fn (): array => $this->inPlaceholderControl(
                $reference,
                $summary,
                $values,
                useArray: true,
            );
            $operations['placeholder_string_control'] = fn (): array => $this->inPlaceholderControl(
                $reference,
                $summary,
                $values,
                useArray: false,
            );
        }

        return [
            'operations' => $operations,
            'pdo' => null,
            'dimensions' => [
                'list_values' => $values,
                'sql_bytes' => strlen($reference->sql),
                'binding_count' => count($reference->bindings),
            ],
            'normalization' => ['items' => $values, 'unit' => 'list_value'],
        ];
    }

    /** @return Scenario */
    private function batch(ScenarioRequest $request): array
    {
        $rows = $this->positiveDimension($request, 'batch');
        $connection = CompilerConnection::for(Driver::Sqlite);
        $builder = $connection->table('batch_rows');
        $fixture = [];
        for ($index = 1; $index <= $rows; ++$index) {
            $fixture[] = ['id' => $index, 'label' => 'row-' . $index, 'enabled' => $index % 2 === 0];
        }
        $reference = CompiledWriteQuery::insertMany($builder, $fixture);
        $summary = self::batchSummary($reference, $rows);
        $operation = static function () use ($builder, $fixture, $rows): array {
            $compiled = CompiledWriteQuery::insertMany($builder, $fixture);

            return self::batchSummary($compiled, $rows);
        };
        $operations = ['insert_many_compile' => $operation];
        if ($request->name->value() === ScenarioName::COMPILER_BATCH_INSERT_HIGH) {
            $columns = array_keys($fixture[0]);
            $pretypedFixture = array_map(
                static fn (array $row): array => array_map(Binding::fromValue(...), $row),
                $fixture,
            );
            $operations['pretyped_insert_many_compile'] = static fn (): array => self::batchSummary(
                CompiledWriteQuery::insertMany($builder, $pretypedFixture),
                $rows,
            );
            $operations['column_array_keys_control'] = fn (): array => $this->batchColumnControl(
                $fixture,
                $columns,
                $summary,
                useArrayKeys: true,
            );
            $operations['column_iteration_control'] = fn (): array => $this->batchColumnControl(
                $fixture,
                $columns,
                $summary,
                useArrayKeys: false,
            );
            $operations['placeholder_arrays_control'] = fn (): array => $this->batchPlaceholderControl(
                $reference,
                $summary,
                $rows,
                count($columns),
                useArrays: true,
            );
            $operations['placeholder_string_control'] = fn (): array => $this->batchPlaceholderControl(
                $reference,
                $summary,
                $rows,
                count($columns),
                useArrays: false,
            );
            $operations['binding_normalization_control'] = fn (): array => $this->batchBindingControl(
                $fixture,
                $summary,
                $rows * count($columns),
            );
        }

        return [
            'operations' => $operations,
            'pdo' => null,
            'dimensions' => [
                'rows' => $rows,
                'columns' => 3,
                'sql_bytes' => strlen($reference->sql),
                'binding_count' => count($reference->bindings),
            ],
            'normalization' => ['items' => $rows, 'unit' => 'row'],
        ];
    }

    /** @param array{sql_hash: string, binding_count: int, first_binding: mixed, last_binding: mixed} $summary
     * @return array{sql_hash: string, binding_count: int, first_binding: mixed, last_binding: mixed}
     */
    private function inPlaceholderControl(
        CompiledQuery $reference,
        array $summary,
        int $values,
        bool $useArray,
    ): array {
        $valuesSql = $useArray
            ? implode(', ', array_fill(0, $values, '?'))
            : str_repeat('?, ', $values - 1) . '?';
        $sql = 'SELECT "id" FROM "benchmark_rows" WHERE "id" IN (' . $valuesSql . ')';
        if ($sql !== $reference->sql) {
            throw new LogicException('The IN placeholder control changed SQL shape.');
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $fixture
     * @param list<string> $columns
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function batchColumnControl(
        array $fixture,
        array $columns,
        array $summary,
        bool $useArrayKeys,
    ): array {
        if ($useArrayKeys) {
            $this->validateFixtureArrayKeys($fixture, $columns);
        } else {
            $this->validateFixtureIterationKeys($fixture, $columns);
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $fixture
     * @param list<string> $columns
     */
    private function validateFixtureArrayKeys(array $fixture, array $columns): void
    {
        foreach ($fixture as $row) {
            if (array_keys($row) !== $columns) {
                throw new LogicException('The batch column control received mismatched columns.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $fixture
     * @param list<string> $columns
     */
    private function validateFixtureIterationKeys(array $fixture, array $columns): void
    {
        $expectedCount = count($columns);
        foreach ($fixture as $row) {
            if (count($row) !== $expectedCount) {
                throw new LogicException('The batch column control received a mismatched column count.');
            }
            $index = 0;
            foreach ($row as $column => $_value) {
                if ($column !== $columns[$index]) {
                    throw new LogicException('The batch column control received mismatched ordered columns.');
                }
                ++$index;
            }
        }
    }

    /** @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function batchPlaceholderControl(
        CompiledQuery $reference,
        array $summary,
        int $rows,
        int $columns,
        bool $useArrays,
    ): array {
        $group = '(' . implode(', ', array_fill(0, $columns, '?')) . ')';
        $valuesSql = $useArrays
            ? implode(', ', array_fill(0, $rows, $group))
            : str_repeat($group . ', ', $rows - 1) . $group;
        $sql = 'INSERT INTO "batch_rows" ("id", "label", "enabled") VALUES ' . $valuesSql;
        if ($sql !== $reference->sql) {
            throw new LogicException('The batch placeholder control changed SQL shape.');
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $fixture
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function batchBindingControl(array $fixture, array $summary, int $expectedBindings): array
    {
        $bindings = [];
        foreach ($fixture as $row) {
            foreach ($row as $value) {
                $bindings[] = Binding::fromValue($value);
            }
        }
        if (
            count($bindings) !== $expectedBindings
            || $bindings[0]->value !== 1
            || $bindings[count($bindings) - 1]->value !== 1
        ) {
            throw new LogicException('The batch binding control changed normalized values.');
        }

        return $summary;
    }

    /** @return Scenario */
    private function attribution(ScenarioRequest $request): array
    {
        $width = $request->scale(['ci' => 50, 'reference' => 250]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $structured = $connection->table('profile_rows', 'p');
        $rawIdentifiers = $connection->table('profile_rows', 'p');
        $collapsedConditions = $connection->table('profile_rows', 'p');
        $structuredProjections = [];
        $rawProjections = [];
        $structuredGroups = [];
        $rawGroups = [];
        $rawConditions = [];
        $conditionBindings = [];

        for ($index = 0; $index < $width; ++$index) {
            $projection = 'p.metric_' . $index;
            $quotedProjection = '"p"."metric_' . $index . '"';
            $quotedCondition = '"p"."filter_' . $index . '" = ?';
            $structuredProjections[] = $projection;
            $rawProjections[] = $connection->raw($quotedProjection);
            $structuredGroups[] = $projection;
            $rawGroups[] = $connection->raw($quotedProjection);
            $rawConditions[] = $quotedCondition;
            $conditionBindings[] = $index;
        }

        $structured->select(...$structuredProjections)->groupBy(...$structuredGroups);
        $rawIdentifiers->select(...$rawProjections)->groupBy(...$rawGroups);
        $collapsedConditions->select(...$structuredProjections)->groupBy(...$structuredGroups);
        foreach ($conditionBindings as $index => $binding) {
            $structured->where('p.filter_' . $index, $binding)->orderBy('p.metric_' . $index);
            $rawIdentifiers
                ->where($connection->raw($rawConditions[$index], [$binding]))
                ->orderBy($rawProjections[$index]);
            $collapsedConditions->orderBy('p.metric_' . $index);
        }
        $collapsedConditions->where($connection->raw(implode(' AND ', $rawConditions), $conditionBindings));

        $reference = $structured->compile();
        foreach ([$rawIdentifiers, $collapsedConditions] as $variant) {
            if (!$this->queriesMatch($variant->compile(), $reference)) {
                throw new LogicException('Compiler attribution variants must produce identical queries.');
            }
        }
        $compiler = $connection->compilerForQueryBuilding();
        $preparedState = $structured->snapshotForCompilation();

        return [
            'operations' => [
                'structured_prepared_compile' => fn (): array => $this->compiledSummary($structured->compile()),
                'raw_identifier_prepared_compile' => fn (): array => $this->compiledSummary(
                    $rawIdentifiers->compile(),
                ),
                'collapsed_clause_prepared_compile' => fn (): array => $this->compiledSummary(
                    $collapsedConditions->compile(),
                ),
                'deep_snapshot_then_compile' => fn (): array => $this->compiledSummary(
                    $compiler->select($structured->snapshotForCompilation()),
                ),
                'shallow_snapshot_then_compile' => fn (): array => $this->compiledSummary(
                    $compiler->select($preparedState->copyForCompilation()),
                ),
            ],
            'pdo' => null,
            'dimensions' => ['shape_width' => $width],
        ];
    }

    /** @return array{binding_count: int, expected_bindings: int, sql_hash: string, first_binding: mixed, last_binding: mixed} */
    private static function batchSummary(CompiledQuery $compiled, int $rows): array
    {
        return [
            'binding_count' => count($compiled->bindings),
            'expected_bindings' => $rows * 3,
            'sql_hash' => hash('sha256', $compiled->sql),
            'first_binding' => $compiled->bindings[0]->value ?? null,
            'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
        ];
    }

    private function queriesMatch(CompiledQuery $left, CompiledQuery $right): bool
    {
        if ($left->sql !== $right->sql || count($left->bindings) !== count($right->bindings)) {
            return false;
        }
        foreach ($left->bindings as $index => $binding) {
            $other = $right->bindings[$index];
            if ($binding->value !== $other->value || $binding->type !== $other->type) {
                return false;
            }
        }

        return true;
    }

    /** @return positive-int */
    private function positiveDimension(ScenarioRequest $request, string $name): int
    {
        $dimension = $request->name->dimension();
        if ($dimension === null || $dimension < 1) {
            throw new \LogicException(sprintf('Missing %s dimension.', $name));
        }

        return $dimension;
    }
}
