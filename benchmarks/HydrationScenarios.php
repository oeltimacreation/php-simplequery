<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use PDO;
use PDOStatement;
use RuntimeException;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class HydrationScenarios
{
    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::HYDRATION => $this->hydration($request),
            ScenarioName::CURSOR_EXHAUSTION => $this->cursorExhaustion($request),
            ScenarioName::CURSOR_EARLY_CLOSE => $this->cursorEarlyClose($request),
            ScenarioName::READ_TERMINALS => $this->readTerminals($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function hydration(ScenarioRequest $request): array
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

        return [
            'operations' => [
                'pdo_associative' => $direct,
                'simplequery_associative' => $associative,
                'simplequery_object' => $objects,
            ],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows, 'payload_bytes' => 96],
        ];
    }

    /** @return Scenario */
    private function cursorExhaustion(ScenarioRequest $request): array
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

        return [
            'operations' => ['associative_cursor' => $associative, 'object_cursor' => $objects],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows, 'payload_bytes' => 96],
        ];
    }

    /** @return Scenario */
    private function cursorEarlyClose(ScenarioRequest $request): array
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

        return [
            'operations' => ['early_close' => $operation],
            'pdo' => $pdo,
            'dimensions' => ['fixture_rows' => $rows, 'consumed_rows' => 1],
        ];
    }

    /** @return Scenario */
    private function readTerminals(ScenarioRequest $request): array
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

        return [
            'operations' => ['simplequery' => $simpleQuery, 'pdo' => $direct],
            'pdo' => $pdo,
            'dimensions' => ['rows' => $rows],
        ];
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
