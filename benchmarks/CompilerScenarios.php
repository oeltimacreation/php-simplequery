<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

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
        $operation = fn (): array => $this->compiledSummary($query->compile());

        return [
            'operations' => ['prepared_builder_compile' => $operation],
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
        $operation = static function () use ($builder, $fixture, $rows): array {
            $compiled = CompiledWriteQuery::insertMany($builder, $fixture);

            return [
                'binding_count' => count($compiled->bindings),
                'expected_bindings' => $rows * 3,
                'sql_hash' => hash('sha256', $compiled->sql),
                'first_binding' => $compiled->bindings[0]->value ?? null,
                'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
            ];
        };

        return [
            'operations' => ['insert_many_compile' => $operation],
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
