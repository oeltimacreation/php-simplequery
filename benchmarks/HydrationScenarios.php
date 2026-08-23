<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\QueryBuilder;
use PDO;
use PDOStatement;
use RuntimeException;

/** @phpstan-import-type Scenario from ScenarioCatalog */
final class HydrationScenarios
{
    private const NARROW_PAYLOAD_COLUMNS = 1;
    private const WIDE_PAYLOAD_COLUMNS = 16;

    /** @return Scenario|null */
    public function prepare(ScenarioRequest $request): ?array
    {
        return match ($request->name->value()) {
            ScenarioName::HYDRATION => $this->hydration($request, false),
            ScenarioName::HYDRATION_WIDE => $this->hydration($request, true),
            ScenarioName::FIRST_ROW => $this->firstRow($request, false),
            ScenarioName::FIRST_ROW_WIDE => $this->firstRow($request, true),
            ScenarioName::CURSOR_EXHAUSTION => $this->cursorExhaustion($request, false),
            ScenarioName::CURSOR_EXHAUSTION_WIDE => $this->cursorExhaustion($request, true),
            ScenarioName::CURSOR_EARLY_CLOSE => $this->cursorEarlyClose($request, false),
            ScenarioName::CURSOR_EARLY_CLOSE_WIDE => $this->cursorEarlyClose($request, true),
            ScenarioName::READ_TERMINALS => $this->readTerminals($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function hydration(ScenarioRequest $request, bool $wide): array
    {
        [$connection, $pdo, $rows, $columns, $rowPayloadBytes] = $this->rowFixture($request, $wide);
        $directAssociative = fn (): array => $this->selectRows($pdo, $columns)->fetchAll(PDO::FETCH_ASSOC);
        $simpleQueryAssociative = fn (): array => $this->builder($connection, $columns)->getAssociative();
        $directObjects = fn (): array => $this->normalizeObjects(
            $this->selectRows($pdo, $columns)->fetchAll(PDO::FETCH_OBJ),
        );
        $simpleQueryObjects = fn (): array => $this->normalizeObjects(
            $this->builder($connection, $columns)->get(),
        );

        return [
            'operations' => [
                'pdo_associative' => $directAssociative,
                'simplequery_associative' => $simpleQueryAssociative,
                'pdo_object' => $directObjects,
                'simplequery_object' => $simpleQueryObjects,
            ],
            'pdo' => $pdo,
            'dimensions' => [
                'rows' => $rows,
                'columns' => count($columns),
                'row_payload_bytes' => $rowPayloadBytes,
            ],
        ];
    }

    /** @return Scenario */
    private function firstRow(ScenarioRequest $request, bool $wide): array
    {
        [$connection, $pdo, $rows, $columns, $rowPayloadBytes] = $this->rowFixture($request, $wide);
        $directAssociative = fn (): ?array => $this->fetchFirstAssociative($pdo, $columns);
        $simpleQueryAssociative = fn (): ?array => $this->builder($connection, $columns)->firstAssociative();
        $directObject = fn (): ?array => $this->fetchFirstObject($pdo, $columns);
        $simpleQueryObject = function () use ($connection, $columns): ?array {
            $row = $this->builder($connection, $columns)->first();

            return $row === null ? null : get_object_vars($row);
        };

        return [
            'operations' => [
                'pdo_associative' => $directAssociative,
                'simplequery_associative' => $simpleQueryAssociative,
                'pdo_object' => $directObject,
                'simplequery_object' => $simpleQueryObject,
            ],
            'pdo' => $pdo,
            'dimensions' => [
                'fixture_rows' => $rows,
                'result_rows' => 1,
                'columns' => count($columns),
                'row_payload_bytes' => $rowPayloadBytes,
            ],
        ];
    }

    /** @return Scenario */
    private function cursorExhaustion(ScenarioRequest $request, bool $wide): array
    {
        [$connection, $pdo, $rows, $columns, $rowPayloadBytes] = $this->rowFixture($request, $wide);
        $directAssociative = fn (): array => $this->drainAssociative($pdo, $columns);
        $simpleQueryAssociative = function () use ($connection, $columns): array {
            $result = [];
            foreach ($this->builder($connection, $columns)->iterateAssociative() as $row) {
                $result[] = $row;
            }

            return $result;
        };
        $directObjects = fn (): array => $this->drainObjects($pdo, $columns);
        $simpleQueryObjects = function () use ($connection, $columns): array {
            $result = [];
            foreach ($this->builder($connection, $columns)->iterate() as $row) {
                $result[] = get_object_vars($row);
            }

            return $result;
        };

        return [
            'operations' => [
                'pdo_associative_cursor' => $directAssociative,
                'simplequery_associative_cursor' => $simpleQueryAssociative,
                'pdo_object_cursor' => $directObjects,
                'simplequery_object_cursor' => $simpleQueryObjects,
            ],
            'pdo' => $pdo,
            'dimensions' => [
                'rows' => $rows,
                'columns' => count($columns),
                'row_payload_bytes' => $rowPayloadBytes,
            ],
        ];
    }

    /** @return Scenario */
    private function cursorEarlyClose(ScenarioRequest $request, bool $wide): array
    {
        [$connection, $pdo, $rows, $columns, $rowPayloadBytes] = $this->rowFixture($request, $wide);
        $directAssociative = fn (): array => $this->closeAssociativeEarly($pdo, $columns);
        $simpleQueryAssociative = function () use ($connection, $columns): array {
            $cursor = $this->builder($connection, $columns)->iterateAssociative();
            $first = null;
            foreach ($cursor as $row) {
                $first = $row;
                break;
            }
            $cursor->close();

            return ['first' => $first, 'closed' => $cursor->isClosed()];
        };
        $directObject = fn (): array => $this->closeObjectEarly($pdo, $columns);
        $simpleQueryObject = function () use ($connection, $columns): array {
            $cursor = $this->builder($connection, $columns)->iterate();
            $first = null;
            foreach ($cursor as $row) {
                $first = get_object_vars($row);
                break;
            }
            $cursor->close();

            return ['first' => $first, 'closed' => $cursor->isClosed()];
        };

        return [
            'operations' => [
                'pdo_associative_cursor' => $directAssociative,
                'simplequery_associative_cursor' => $simpleQueryAssociative,
                'pdo_object_cursor' => $directObject,
                'simplequery_object_cursor' => $simpleQueryObject,
            ],
            'pdo' => $pdo,
            'dimensions' => [
                'fixture_rows' => $rows,
                'consumed_rows' => 1,
                'columns' => count($columns),
                'row_payload_bytes' => $rowPayloadBytes,
            ],
        ];
    }

    /** @return Scenario */
    private function readTerminals(ScenarioRequest $request): array
    {
        [$connection, $pdo, $rows, $columns] = $this->rowFixture(
            $request,
            false,
            ['ci' => 1_000, 'reference' => 10_000],
        );
        $simpleQuery = fn (): array => [
            'first' => $this->builder($connection, $columns)->firstAssociative(),
            'count' => $connection->table('benchmark_rows')->count(),
            'sum' => $connection->table('benchmark_rows')->sum('id'),
        ];
        $direct = function () use ($pdo, $columns): array {
            return [
                'first' => $this->fetchFirstAssociative($pdo, $columns),
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
     * @param array{ci: int, reference: int}|null $sizes
     * @return array{Connection, PDO, int, non-empty-list<string>, int}
     */
    private function rowFixture(ScenarioRequest $request, bool $wide, ?array $sizes = null): array
    {
        $sizes ??= $wide
            ? ['ci' => 1_000, 'reference' => 10_000]
            : ['ci' => 1_000, 'reference' => 100_000];
        $rows = $request->scale($sizes);
        $payloadColumns = $wide ? self::WIDE_PAYLOAD_COLUMNS : self::NARROW_PAYLOAD_COLUMNS;
        $payloadBytes = $wide ? 24 : 96;
        $columns = ['id', 'category'];
        $definitions = ['id INTEGER PRIMARY KEY', 'category TEXT NOT NULL'];
        for ($index = 1; $index <= $payloadColumns; ++$index) {
            $column = $wide ? sprintf('payload_%02d', $index) : 'payload';
            $columns[] = $column;
            $definitions[] = $column . ' TEXT NOT NULL';
        }

        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE benchmark_rows (' . implode(', ', $definitions) . ')');
        $insert = $pdo->prepare(sprintf(
            'INSERT INTO benchmark_rows (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        $pdo->beginTransaction();
        for ($id = 1; $id <= $rows; ++$id) {
            $values = [$id, 'category-' . ($id % 10)];
            for ($index = 1; $index <= $payloadColumns; ++$index) {
                $values[] = str_repeat((string) (($id + $index) % 10), $payloadBytes);
            }
            $insert->execute($values);
        }
        $pdo->commit();

        return [$connection, $pdo, $rows, $columns, $payloadColumns * $payloadBytes];
    }

    /** @param non-empty-list<string> $columns */
    private function builder(Connection $connection, array $columns): QueryBuilder
    {
        return $connection->table('benchmark_rows')->select(...$columns)->orderBy('id');
    }

    /**
     * @param array<array-key, object> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeObjects(array $rows): array
    {
        return array_values(array_map(static fn (object $row): array => get_object_vars($row), $rows));
    }

    /** @param non-empty-list<string> $columns */
    private function selectRows(PDO $pdo, array $columns, bool $first = false): PDOStatement
    {
        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM benchmark_rows ORDER BY id';
        if ($first) {
            $sql .= ' LIMIT 1';
        }

        return $this->query($pdo, $sql);
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array<mixed>|null
     */
    private function fetchFirstAssociative(PDO $pdo, array $columns): ?array
    {
        $row = $this->selectRows($pdo, $columns, true)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('The benchmark query returned a non-array associative row.');
        }

        return $row;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array<string, mixed>|null
     */
    private function fetchFirstObject(PDO $pdo, array $columns): ?array
    {
        $row = $this->selectRows($pdo, $columns, true)->fetch(PDO::FETCH_OBJ);
        if ($row === false) {
            return null;
        }
        if (!is_object($row)) {
            throw new RuntimeException('The benchmark query returned a non-object row.');
        }

        return get_object_vars($row);
    }

    /**
     * @param non-empty-list<string> $columns
     * @return list<array<mixed>>
     */
    private function drainAssociative(PDO $pdo, array $columns): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The benchmark cursor returned a non-array associative row.');
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function drainObjects(PDO $pdo, array $columns): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_OBJ)) !== false) {
            if (!is_object($row)) {
                throw new RuntimeException('The benchmark cursor returned a non-object row.');
            }
            $result[] = get_object_vars($row);
        }

        return $result;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array{first: array<mixed>|null, closed: bool}
     */
    private function closeAssociativeEarly(PDO $pdo, array $columns): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && !is_array($row)) {
            throw new RuntimeException('The benchmark cursor returned a non-array associative row.');
        }

        return ['first' => $row === false ? null : $row, 'closed' => $statement->closeCursor()];
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array{first: array<string, mixed>|null, closed: bool}
     */
    private function closeObjectEarly(PDO $pdo, array $columns): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $row = $statement->fetch(PDO::FETCH_OBJ);
        if ($row !== false && !is_object($row)) {
            throw new RuntimeException('The benchmark cursor returned a non-object row.');
        }

        return [
            'first' => $row === false ? null : get_object_vars($row),
            'closed' => $statement->closeCursor(),
        ];
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
