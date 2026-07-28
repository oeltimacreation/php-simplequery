<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\Testing\CompilerConnection;

final class ProductionCompilerScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name->value()) {
            ScenarioName::PRODUCTION_REPORT_COMPILE => $this->report($request),
            ScenarioName::PRODUCTION_COUNT_COMPILE => $this->count($request),
            default => null,
        };
    }

    private function report(ScenarioRequest $request): PreparedScenario
    {
        $filterCount = $request->scale(['ci' => 12, 'reference' => 40]);
        $inListSize = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $operation = static function () use ($filterCount, $inListSize): array {
            $compiled = self::reportQuery($filterCount, $inListSize);
            ProductionCompilerExpectations::assertReport($compiled, $filterCount, $inListSize);

            return self::summary($compiled);
        };

        return new PreparedScenario(
            ['build_and_compile' => $operation],
            null,
            ['projections' => 12, 'joins' => 3, 'conditional_filters' => $filterCount, 'in_values' => $inListSize],
        );
    }

    private function count(ScenarioRequest $request): PreparedScenario
    {
        $inListSize = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $operation = static function () use ($inListSize): array {
            $connection = CompilerConnection::for(Driver::Sqlite);
            $compiled = $connection
                ->table('report_events', 'r')
                ->select($connection->raw('COUNT(DISTINCT r.account_id) AS aggregate'))
                ->where('r.created_at', '>=', '2026-01-01 00:00:00')
                ->where('r.created_at', '<', '2026-02-01 00:00:00')
                ->whereIn('r.account_id', range(1, $inListSize))
                ->compile();
            ProductionCompilerExpectations::assertCount($compiled, $inListSize);

            return self::summary($compiled);
        };

        return new PreparedScenario(
            ['build_and_compile' => $operation],
            null,
            ['count_semantics' => 1, 'in_values' => $inListSize],
        );
    }

    private static function reportQuery(int $filterCount, int $inListSize): CompiledQuery
    {
        $connection = CompilerConnection::for(Driver::Sqlite);
        $query = $connection
            ->table('report_events', 'r')
            ->select(
                'r.id',
                Identifier::of('r.account_id')->as('account_id'),
                Identifier::of('a.name')->as('account_name'),
                Identifier::of('t.name')->as('team_name'),
                Identifier::of('rg.name')->as('region_name'),
                'r.status',
                'r.kind',
                'r.created_at',
                'r.updated_at',
                'r.score',
                'r.payload_size',
                $connection->raw('COALESCE(r.score, ?) AS normalized_score', [0]),
            )
            ->join(Identifier::of('accounts')->as('a'), 'a.id', '=', 'r.account_id')
            ->leftJoin(Identifier::of('teams')->as('t'), static function (JoinClause $join): void {
                $join->on('t.id', '=', 'r.team_id')->onValue('t.enabled', '=', true);
            })
            ->leftJoin(Identifier::of('regions')->as('rg'), static function (JoinClause $join) use ($connection): void {
                $join->on('rg.id', '=', 'a.region_id')->on($connection->raw('rg.archived_at IS NULL'));
            })
            ->where('r.created_at', '>=', '2026-01-01 00:00:00')
            ->where('r.created_at', '<', '2026-02-01 00:00:00')
            ->where(static function (ConditionGroup $group): void {
                $group
                    ->where('r.status', '=', 'ready')
                    ->orWhere(static function (ConditionGroup $nested): void {
                        $nested->where('r.status', '=', 'queued')->whereNotNull('r.updated_at');
                    });
            })
            ->where($connection->raw('COALESCE(r.score, ?) >= ?', [0, 50]))
            ->whereIn('r.account_id', range(1, $inListSize));
        for ($filter = 0; $filter < $filterCount; ++$filter) {
            $query->where('r.filter_' . $filter, '>=', $filter);
        }

        return $query->orderBy('r.created_at', 'DESC')->orderBy('r.id')->limit(250)->compile();
    }

    /** @return array{sql_hash: string, binding_count: int, first_binding: mixed, last_binding: mixed} */
    private static function summary(CompiledQuery $compiled): array
    {
        return [
            'sql_hash' => hash('sha256', $compiled->sql),
            'binding_count' => count($compiled->bindings),
            'first_binding' => $compiled->bindings[0]->value ?? null,
            'last_binding' => $compiled->bindings[count($compiled->bindings) - 1]->value ?? null,
        ];
    }
}
