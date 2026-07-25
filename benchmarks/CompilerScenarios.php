<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;

final class CompilerScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name) {
            ScenarioName::CompilerPredicates10,
            ScenarioName::CompilerPredicates100,
            ScenarioName::CompilerPredicates1000 => $this->predicates($request),
            ScenarioName::CompilerShapes => $this->shapes($request),
            ScenarioName::CompilerRepeated => $this->repeated($request),
            ScenarioName::BatchCompile => $this->batch($request),
            default => null,
        };
    }

    private function predicates(ScenarioRequest $request): PreparedScenario
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

        return new PreparedScenario(
            ['simplequery' => $operation],
            null,
            ['predicates' => $predicates],
        );
    }

    private function shapes(ScenarioRequest $request): PreparedScenario
    {
        $width = $request->scale(['ci' => 50, 'reference' => 250]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $operation = static function () use ($connection, $width): array {
            $subquery = $connection->table('roles')->select('user_id')->where('enabled', true);
            $query = $connection
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
            $compiled = $query->compile();

            return [
                'sql_hash' => hash('sha256', $compiled->sql),
                'binding_count' => count($compiled->bindings),
                'first_binding' => $compiled->bindings[0]->value ?? null,
                'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
            ];
        };

        return new PreparedScenario(
            ['build_and_compile' => $operation],
            null,
            ['shape_width' => $width],
        );
    }

    private function repeated(ScenarioRequest $request): PreparedScenario
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

        return new PreparedScenario(
            ['repeated_compile' => $operation],
            null,
            ['compiles' => $compiles],
        );
    }

    private function batch(ScenarioRequest $request): PreparedScenario
    {
        $rows = $request->scale(['ci' => 200, 'reference' => 1_000]);
        $connection = CompilerConnection::for(Driver::Sqlite);
        $fixture = [];
        for ($index = 1; $index <= $rows; ++$index) {
            $fixture[] = ['id' => $index, 'label' => 'row-' . $index, 'enabled' => $index % 2 === 0];
        }
        $operation = static function () use ($connection, $fixture, $rows): array {
            $compiled = CompiledWriteQuery::insertMany($connection->table('batch_rows'), $fixture);

            return [
                'binding_count' => count($compiled->bindings),
                'expected_bindings' => $rows * 3,
                'sql_hash' => hash('sha256', $compiled->sql),
            ];
        };

        return new PreparedScenario(
            ['insert_many_compile' => $operation],
            null,
            ['rows' => $rows, 'columns' => 3],
        );
    }
}
