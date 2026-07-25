<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;

final class ObserverScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name) {
            ScenarioName::Observer => $this->observer($request),
            ScenarioName::ObserverBindings1,
            ScenarioName::ObserverBindings10,
            ScenarioName::ObserverBindings50 => $this->bindings($request),
            default => null,
        };
    }

    private function observer(ScenarioRequest $request): PreparedScenario
    {
        $calls = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $off = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $noop = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $this->noopObserver());
        $recording = new RecordingQueryObserver(10);
        $recorded = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $recording);
        $operation = static function (Connection $connection) use ($calls): array {
            $value = null;
            for ($call = 0; $call < $calls; ++$call) {
                $value = $connection->query('SELECT ? AS value', [$call])->firstAssociative()['value'] ?? null;
            }

            return ['calls' => $calls, 'last' => $value];
        };

        return new PreparedScenario(
            [
                'observer_off' => static fn (): array => $operation($off),
                'observer_noop' => static fn (): array => $operation($noop),
                'observer_bounded_recording' => static fn (): array => $operation($recorded),
            ],
            $off->pdo(),
            ['calls_per_sample' => $calls],
        );
    }

    private function bindings(ScenarioRequest $request): PreparedScenario
    {
        $bindings = $request->name->dimension() ?? throw new \LogicException('Missing binding dimension.');
        $calls = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $off = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $noop = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $this->noopObserver());
        $sql = 'SELECT ' . implode(' + ', array_fill(0, $bindings, '?')) . ' AS value';
        $values = array_fill(0, $bindings, 1);
        $operation = static function (Connection $connection) use ($calls, $sql, $values): array {
            $value = null;
            for ($call = 0; $call < $calls; ++$call) {
                $value = $connection->query($sql, $values)->firstAssociative()['value'] ?? null;
            }

            return ['calls' => $calls, 'value' => $value];
        };

        return new PreparedScenario(
            [
                'observer_off' => static fn (): array => $operation($off),
                'observer_noop' => static fn (): array => $operation($noop),
            ],
            $off->pdo(),
            ['bindings' => $bindings, 'calls_per_sample' => $calls],
        );
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
}
