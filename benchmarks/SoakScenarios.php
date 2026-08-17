<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

/**
 * Memory-stability soak scenarios for streaming, batch-write, and cursor
 * workloads. Timing medians are recorded but never interpreted as portable
 * thresholds; the allocation fields drive the soak memory gate in run.php.
 */
/** @phpstan-import-type Scenario from ScenarioCatalog */
final class SoakScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::STREAMING_CURSOR_SOAK => $this->streamingCursor($request),
            ScenarioName::BATCH_WRITE_SOAK => $this->batchWrite($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function streamingCursor(ScenarioRequest $request): array
    {
        $rows = $request->scale(['ci' => 5_000, 'reference' => 50_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE soak_rows (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO soak_rows (id, payload) VALUES (?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= $rows; ++$id) {
            $insert->execute([$id, str_repeat((string) ($id % 10), 64)]);
        }
        $pdo->commit();
        $operation = static function () use ($connection): array {
            $count = 0;
            foreach ($connection->table('soak_rows')->orderBy('id')->iterate() as $row) {
                ++$count;
            }

            return ['count' => $count];
        };

        return [
            'operations' => ['cursor_drain' => $operation],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows, 'payload_bytes' => 64],
        ];
    }

    /** @return Scenario */
    private function batchWrite(ScenarioRequest $request): array
    {
        $rows = $request->scale(['ci' => 500, 'reference' => 2_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE soak_batch (id INTEGER PRIMARY KEY, label TEXT NOT NULL, enabled INTEGER NOT NULL)');
        $fixture = [];
        for ($id = 1; $id <= $rows; ++$id) {
            $fixture[] = ['id' => $id, 'label' => 'soak-' . $id, 'enabled' => $id % 2 === 0];
        }
        $operation = static function () use ($connection, $fixture): array {
            return $connection->transaction(static function (Connection $database) use ($fixture): array {
                $affected = $database->table('soak_batch')->insertMany($fixture);
                $database->query('DELETE FROM soak_batch')->execute();

                return ['affected' => $affected];
            });
        };

        return [
            'operations' => ['batch_write' => $operation],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows, 'columns' => 3],
        ];
    }
}
