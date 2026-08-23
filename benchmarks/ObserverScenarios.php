<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use RuntimeException;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class ObserverScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::OBSERVER => $this->observer($request, 1),
            ScenarioName::OBSERVER_WIDE => $this->observer($request, 50),
            default => null,
        };
    }

    /** @return Scenario */
    private function observer(ScenarioRequest $request, int $bindingCount): array
    {
        $calls = $request->scale(['ci' => 500, 'reference' => 1_000]);
        $off = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $noop = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $this->noopObserver());
        $recording = new RecordingQueryObserver(10);
        $recorded = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $recording);
        $failing = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $this->failingObserver());
        $projections = [];
        $bindings = [];
        for ($index = 1; $index <= $bindingCount; ++$index) {
            $projections[] = sprintf('? AS value_%02d', $index);
            $bindings[] = $index;
        }
        $sql = 'SELECT ' . implode(', ', $projections);
        $lastColumn = sprintf('value_%02d', $bindingCount);
        $operation = static function (Connection $connection) use ($bindings, $calls, $lastColumn, $sql): array {
            $value = null;
            for ($call = 0; $call < $calls; ++$call) {
                $value = $connection->query($sql, $bindings)->firstAssociative()[$lastColumn] ?? null;
            }

            return ['calls' => $calls, 'last' => $value];
        };

        return [
            'operations' => [
                'observer_off' => static fn (): array => $operation($off),
                'observer_noop' => static fn (): array => $operation($noop),
                'observer_bounded_recording' => static fn (): array => $operation($recorded),
                'observer_failing' => static fn (): array => $operation($failing),
            ],
            'pdo' => $off->pdo(),
            'dimensions' => ['calls_per_sample' => $calls, 'bindings_per_call' => $bindingCount],
        ];
    }

    private function noopObserver(): QueryObserver
    {
        return new class implements QueryObserver {
            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
            }
        };
    }

    private function failingObserver(): QueryObserver
    {
        return new class implements QueryObserver {
            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
                throw new RuntimeException('Controlled benchmark observer failure.');
            }
        };
    }
}
