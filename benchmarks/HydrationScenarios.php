<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use PDO;
use PDOStatement;
use RuntimeException;

final class HydrationScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name) {
            ScenarioName::Hydration => $this->hydration($request),
            ScenarioName::CursorExhaustion => $this->cursorExhaustion($request),
            ScenarioName::CursorEarlyClose => $this->cursorEarlyClose($request),
            ScenarioName::ReadTerminals => $this->readTerminals($request),
            ScenarioName::HydrationPdoAssociative,
            ScenarioName::HydrationSimpleQueryAssociative,
            ScenarioName::HydrationSimpleQueryObject,
            ScenarioName::CursorSimpleQueryAssociative,
            ScenarioName::CursorSimpleQueryObject => $this->standalone($request),
            default => null,
        };
    }

    private function hydration(ScenarioRequest $request): PreparedScenario
    {
        [$connection, $pdo, $rows] = $this->rowFixture($request);
        $direct = fn (): array => $this->selectRows($pdo)->fetchAll(PDO::FETCH_ASSOC);
        $associative = static fn (): array => $connection->table('benchmark_rows')
            ->orderBy('id')
            ->getAssociative();
        $objects = static fn (): array => array_map(
            static fn (object $row): array => get_object_vars($row),
            $connection->table('benchmark_rows')->orderBy('id')->get(),
        );

        return new PreparedScenario(
            [
                'pdo_associative' => $direct,
                'simplequery_associative' => $associative,
                'simplequery_object' => $objects,
            ],
            $pdo,
            ['rows' => $rows, 'payload_bytes' => 96],
        );
    }

    private function cursorExhaustion(ScenarioRequest $request): PreparedScenario
    {
        [$connection, $pdo, $rows] = $this->rowFixture($request);
        $associative = static function () use ($connection): array {
            $result = [];
            foreach ($connection->table('benchmark_rows')->orderBy('id')->iterateAssociative() as $row) {
                $result[] = $row;
            }

            return $result;
        };
        $objects = static function () use ($connection): array {
            $result = [];
            foreach ($connection->table('benchmark_rows')->orderBy('id')->iterate() as $row) {
                $result[] = get_object_vars($row);
            }

            return $result;
        };

        return new PreparedScenario(
            ['associative_cursor' => $associative, 'object_cursor' => $objects],
            $pdo,
            ['rows' => $rows, 'payload_bytes' => 96],
        );
    }

    private function cursorEarlyClose(ScenarioRequest $request): PreparedScenario
    {
        [$connection, $pdo, $rows] = $this->rowFixture($request);
        $operation = static function () use ($connection): array {
            $cursor = $connection->table('benchmark_rows')->orderBy('id')->iterateAssociative();
            $first = null;
            foreach ($cursor as $row) {
                $first = $row;
                break;
            }
            $cursor->close();

            return ['first' => $first, 'closed' => $cursor->isClosed()];
        };

        return new PreparedScenario(
            ['early_close' => $operation],
            $pdo,
            ['fixture_rows' => $rows, 'consumed_rows' => 1],
        );
    }

    private function readTerminals(ScenarioRequest $request): PreparedScenario
    {
        [$connection, $pdo, $rows] = $this->rowFixture(
            $request,
            ['ci' => 1_000, 'reference' => 10_000],
        );
        $simpleQuery = static fn (): array => [
            'first' => $connection->table('benchmark_rows')->orderBy('id')->firstAssociative(),
            'count' => $connection->table('benchmark_rows')->count(),
            'sum' => $connection->table('benchmark_rows')->sum('id'),
        ];
        $direct = function () use ($pdo): array {
            $first = $this->selectFirstRow($pdo)->fetch(PDO::FETCH_ASSOC);

            return [
                'first' => $first,
                'count' => (int) $this->countRows($pdo)->fetchColumn(),
                'sum' => $this->sumIds($pdo)->fetchColumn(),
            ];
        };

        return new PreparedScenario(
            ['simplequery' => $simpleQuery, 'pdo' => $direct],
            $pdo,
            ['rows' => $rows],
        );
    }

    private function standalone(ScenarioRequest $request): PreparedScenario
    {
        [$connection, $pdo, $rows] = $this->rowFixture($request);
        $operation = match ($request->name) {
            ScenarioName::HydrationPdoAssociative => fn (): array => $this->selectRows($pdo)
                ->fetchAll(PDO::FETCH_ASSOC),
            ScenarioName::HydrationSimpleQueryAssociative => static fn (): array => $connection
                ->table('benchmark_rows')
                ->orderBy('id')
                ->getAssociative(),
            ScenarioName::HydrationSimpleQueryObject => static fn (): array => array_map(
                static fn (object $row): array => get_object_vars($row),
                $connection->table('benchmark_rows')->orderBy('id')->get(),
            ),
            ScenarioName::CursorSimpleQueryAssociative => static function () use ($connection): array {
                $result = [];
                foreach ($connection->table('benchmark_rows')->orderBy('id')->iterateAssociative() as $row) {
                    $result[] = $row;
                }

                return $result;
            },
            ScenarioName::CursorSimpleQueryObject => static function () use ($connection): array {
                $result = [];
                foreach ($connection->table('benchmark_rows')->orderBy('id')->iterate() as $row) {
                    $result[] = get_object_vars($row);
                }

                return $result;
            },
            default => throw new \LogicException('Unsupported standalone hydration mode.'),
        };

        return new PreparedScenario(
            [$request->name->value => $operation],
            $pdo,
            ['rows' => $rows, 'payload_bytes' => 96],
        );
    }

    /**
     * @param array{ci: int, reference: int} $sizes
     * @return array{Connection, PDO, int}
     */
    private function rowFixture(
        ScenarioRequest $request,
        array $sizes = ['ci' => 2_000, 'reference' => 100_000],
    ): array {
        $rows = $request->scale($sizes);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec(
            'CREATE TABLE benchmark_rows ('
            . 'id INTEGER PRIMARY KEY, category TEXT NOT NULL, payload TEXT NOT NULL)',
        );
        $insert = $pdo->prepare('INSERT INTO benchmark_rows (id, category, payload) VALUES (?, ?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= $rows; ++$id) {
            $insert->execute([$id, 'category-' . ($id % 10), str_repeat((string) ($id % 10), 96)]);
        }
        $pdo->commit();

        return [$connection, $pdo, $rows];
    }

    private function selectRows(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT id, category, payload FROM benchmark_rows ORDER BY id');
    }

    private function selectFirstRow(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT * FROM benchmark_rows ORDER BY id LIMIT 1');
    }

    private function countRows(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT COUNT(*) FROM benchmark_rows');
    }

    private function sumIds(PDO $pdo): PDOStatement
    {
        return $this->query($pdo, 'SELECT SUM(id) FROM benchmark_rows');
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
