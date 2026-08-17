<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class ObserverScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::OBSERVER => $this->observer($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function observer(ScenarioRequest $request): array
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

        return [
            'operations' => [
                'observer_off' => static fn (): array => $operation($off),
                'observer_noop' => static fn (): array => $operation($noop),
                'observer_bounded_recording' => static fn (): array => $operation($recorded),
            ],
            'pdo' => $off->pdo(),
            'dimensions' => ['calls_per_sample' => $calls],
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
}
