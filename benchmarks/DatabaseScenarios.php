<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use PDO;
use PDOStatement;
use RuntimeException;

final class DatabaseScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name) {
            ScenarioName::BatchExecute => $this->batch($request),
            ScenarioName::Transactions => $this->transactions($request),
            ScenarioName::Lifecycle, ScenarioName::LifecycleSoak => $this->lifecycle($request),
            ScenarioName::MigrationQuery => $this->migration(),
            default => null,
        };
    }

    private function batch(ScenarioRequest $request): PreparedScenario
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
        $direct = function () use ($pdo, $fixture): array {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO batch_rows (id, label) VALUES (?, ?)');
            foreach ($fixture as $row) {
                $statement->execute([$row['id'], $row['label']]);
            }
            $count = (int) $this->rowCount($pdo)->fetchColumn();
            $pdo->exec('DELETE FROM batch_rows');
            $pdo->commit();

            return ['affected' => count($fixture), 'count' => $count];
        };

        return new PreparedScenario(
            ['simplequery_insert_many' => $simpleQuery, 'pdo_prepared_loop' => $direct],
            $pdo,
            ['rows' => $rows],
        );
    }

    private function transactions(ScenarioRequest $request): PreparedScenario
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

        return new PreparedScenario(
            ['simplequery_managed' => $simpleQuery, 'pdo_control' => $direct],
            $pdo,
            ['transactions_per_sample' => $transactions],
        );
    }

    private function lifecycle(ScenarioRequest $request): PreparedScenario
    {
        $loops = $request->name === ScenarioName::LifecycleSoak
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

        return new PreparedScenario(
            ['simplequery' => $simpleQuery, 'pdo' => $direct],
            null,
            ['loops_per_sample' => $loops],
        );
    }

    private function migration(): PreparedScenario
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE benchmark_teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec(
            'CREATE TABLE benchmark_members ('
            . 'id INTEGER PRIMARY KEY, team_id INTEGER NOT NULL, name TEXT NOT NULL, '
            . 'active INTEGER NOT NULL, score INTEGER)',
        );
        $connection->table('benchmark_teams')->insertMany([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
            ['id' => 3, 'name' => 'Gamma'],
        ]);
        $members = [];
        for ($id = 1; $id <= 500; ++$id) {
            $members[] = [
                'id' => $id,
                'team_id' => (($id - 1) % 3) + 1,
                'name' => 'Member ' . $id,
                'active' => $id % 4 !== 0,
                'score' => $id % 100,
            ];
        }
        $connection->table('benchmark_members')->insertMany($members);

        $simpleQuery = static function () use ($connection): array {
            return $connection
                ->table('benchmark_members', 'm')
                ->select(
                    'm.id',
                    Identifier::of('m.name')->as('member_name'),
                    Identifier::of('t.name')->as('team_name'),
                    'm.score',
                )
                ->join(Identifier::of('benchmark_teams')->as('t'), 't.id', '=', 'm.team_id')
                ->where('m.active', true)
                ->where('m.score', '>=', 50)
                ->orderBy('m.id')
                ->limit(100)
                ->getAssociative();
        };
        $direct = static function () use ($pdo): array {
            $statement = $pdo->prepare(
                'SELECT m.id, m.name AS member_name, t.name AS team_name, m.score FROM benchmark_members m '
                . 'INNER JOIN benchmark_teams t ON t.id = m.team_id '
                . 'WHERE m.active = ? AND m.score >= ? ORDER BY m.id LIMIT 100',
            );
            $statement->execute([1, 50]);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };

        return new PreparedScenario(
            ['simplequery' => $simpleQuery, 'pdo' => $direct],
            $pdo,
            ['fixture_rows' => 500, 'result_rows' => 100],
        );
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
