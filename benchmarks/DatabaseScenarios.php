<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use PDO;
use PDOStatement;
use RuntimeException;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class DatabaseScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::BATCH_EXECUTE => $this->batch($request),
            ScenarioName::TRANSACTIONS => $this->transactions($request),
            ScenarioName::LIFECYCLE, ScenarioName::LIFECYCLE_SOAK => $this->lifecycle($request),
            ScenarioName::TERMINAL_REUSE => $this->terminalReuse($request),
            ScenarioName::EXECUTION_CLEANUP => $this->executionCleanup($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function executionCleanup(ScenarioRequest $request): array
    {
        $calls = $request->scale(['ci' => 500, 'reference' => 5_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $direct = static function (bool $redundant) use ($calls, $pdo): array {
            $last = null;
            for ($index = 0; $index < $calls; ++$index) {
                $statement = $pdo->prepare('SELECT ? AS value');
                $statement->bindValue(1, $index, PDO::PARAM_INT);
                $statement->execute();
                $last = $statement->fetchColumn();
                $statement->closeCursor();
                if ($redundant) {
                    $statement->closeCursor();
                }
            }

            return ['calls' => $calls, 'last' => $last];
        };
        $simpleQuery = static function () use ($calls, $connection): array {
            $last = null;
            for ($index = 0; $index < $calls; ++$index) {
                $last = $connection->query('SELECT ? AS value', [$index])->firstAssociative()['value'] ?? null;
            }

            return ['calls' => $calls, 'last' => $last];
        };

        return [
            'operations' => [
                'pdo_single_close_control' => static fn (): array => $direct(false),
                'pdo_redundant_close_control' => static fn (): array => $direct(true),
                'simplequery_terminal' => $simpleQuery,
            ],
            'pdo' => $pdo,
            'dimensions' => ['calls_per_sample' => $calls],
        ];
    }

    /** @return Scenario */
    private function batch(ScenarioRequest $request): array
    {
        $rows = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE batch_rows (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $fixture = [];
        for ($id = 1; $id <= $rows; ++$id) {
            $fixture[] = ['id' => $id, 'label' => 'row-' . $id];
        }
        $simpleQuery = static function () use ($connection, $fixture): array {
            return $connection->transaction(static function (Connection $database) use ($fixture): array {
                $affected = $database->table('batch_rows')->insertMany($fixture);
                $count = $database->table('batch_rows')->count();
                $database->query('DELETE FROM batch_rows')->execute();

                return ['affected' => $affected, 'count' => $count];
            });
        };
        $direct = function (bool $redundantCount = false) use ($pdo, $fixture): array {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO batch_rows (id, label) VALUES (?, ?)');
            $affected = 0;
            foreach ($fixture as $row) {
                $statement->execute([$row['id'], $row['label']]);
                $affected += $statement->rowCount();
                if ($redundantCount) {
                    $statement->rowCount();
                }
            }
            $count = (int) $this->rowCount($pdo)->fetchColumn();
            $pdo->exec('DELETE FROM batch_rows');
            $pdo->commit();

            return ['affected' => $affected, 'count' => $count];
        };

        return [
            'operations' => [
                'simplequery_insert_many' => $simpleQuery,
                'pdo_prepared_loop' => static fn (): array => $direct(),
                'pdo_redundant_count_loop' => static fn (): array => $direct(true),
            ],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows],
        ];
    }

    /** @return Scenario */
    private function transactions(ScenarioRequest $request): array
    {
        $transactions = $request->scale(['ci' => 20, 'reference' => 100]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $simpleQuery = static function () use ($connection, $transactions): array {
            $depth = null;
            for ($index = 0; $index < $transactions; ++$index) {
                $depth = $connection->transaction(static function (Connection $database): int {
                    return $database->transaction(static fn (Connection $nested): int => $nested->transactionDepth());
                });
            }

            return ['transactions' => $transactions, 'nested_depth' => $depth];
        };
        $direct = static function () use ($pdo, $transactions): array {
            for ($index = 0; $index < $transactions; ++$index) {
                $pdo->beginTransaction();
                $pdo->exec('SAVEPOINT benchmark_nested');
                $pdo->exec('RELEASE SAVEPOINT benchmark_nested');
                $pdo->commit();
            }

            return ['transactions' => $transactions, 'nested_depth' => 2];
        };

        return [
            'operations' => ['simplequery_managed' => $simpleQuery, 'pdo_control' => $direct],
            'pdo' => $pdo,
            'dimensions' => ['transactions_per_sample' => $transactions],
        ];
    }

    /** @return Scenario */
    private function lifecycle(ScenarioRequest $request): array
    {
        $loops = $request->name->value() === ScenarioName::LIFECYCLE_SOAK
            ? $request->scale(['ci' => 500, 'reference' => 5_000])
            : $request->scale(['ci' => 20, 'reference' => 100]);
        $simpleQuery = static function () use ($loops): array {
            $last = null;
            for ($index = 0; $index < $loops; ++$index) {
                $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
                $last = $connection->query('SELECT 1 AS value')->firstAssociative()['value'] ?? null;
                $connection->close();
            }

            return ['loops' => $loops, 'last' => $last];
        };
        $direct = function () use ($loops): array {
            $last = null;
            for ($index = 0; $index < $loops; ++$index) {
                $pdo = $this->pdo();
                $last = $this->selectOne($pdo)->fetchColumn();
                unset($pdo);
            }

            return ['loops' => $loops, 'last' => $last];
        };

        return [
            'operations' => ['simplequery' => $simpleQuery, 'pdo' => $direct],
            'pdo' => null,
            'dimensions' => ['loops_per_sample' => $loops],
        ];
    }

    /** @return Scenario */
    private function terminalReuse(ScenarioRequest $request): array
    {
        $loops = $request->scale(['ci' => 200, 'reference' => 1_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE terminal_rows (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $fixture = [];
        for ($id = 1; $id <= 100; ++$id) {
            $fixture[] = ['id' => $id, 'label' => 'row-' . $id];
        }
        $connection->table('terminal_rows')->insertMany($fixture);
        $operation = static function () use ($connection, $loops): array {
            $lastLabel = null;
            $lastCount = null;
            for ($index = 0; $index < $loops; ++$index) {
                $id = ($index % 100) + 1;
                $lastLabel = $connection->table('terminal_rows')->where('id', $id)->first()->label ?? null;
                $lastCount = $connection->table('terminal_rows')->where('id', '<=', $id)->count();
            }

            return ['loops' => $loops, 'last_label' => $lastLabel, 'last_count' => $lastCount];
        };

        return [
            'operations' => ['terminal_reuse' => $operation],
            'pdo' => $pdo,
            'dimensions' => ['loops' => $loops, 'rows' => 100],
        ];
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function rowCount(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT COUNT(*) FROM batch_rows');
    }

    private function selectOne(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT 1 AS value');
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('The benchmark query did not return a statement.');
        }

        return $statement;
    }
}
