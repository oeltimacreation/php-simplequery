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
            ScenarioName::HYDRATION_ATTRIBUTION => $this->hydrationAttribution($request),
            ScenarioName::RESULT_MEMORY_SMALL,
            ScenarioName::RESULT_MEMORY_NORMAL,
            ScenarioName::RESULT_MEMORY_HIGH => $this->resultMemory($request),
            ScenarioName::READ_TERMINALS => $this->readTerminals($request),
            default => null,
        };
    }

    /** @return Scenario */
    private function hydrationAttribution(ScenarioRequest $request): array
    {
        $rowCount = $request->scale(['ci' => 20_000, 'reference' => 100_000]);
        $columnCount = 18;
        $row = $this->attributionRow($columnCount);

        return [
            'operations' => [
                'array_keys_validation_control' => static fn (): array => self::countAttributionKeys(
                    $row,
                    $rowCount,
                    true,
                ),
                'direct_key_iteration_control' => static fn (): array => self::countAttributionKeys(
                    $row,
                    $rowCount,
                    false,
                ),
            ],
            'pdo' => null,
            'dimensions' => ['rows' => $rowCount, 'columns' => $columnCount],
        ];
    }

    /**
     * @param array<array-key, int> $row
     * @return array{keys: int, last_key: string|null}
     */
    private static function countAttributionKeys(array $row, int $rowCount, bool $useArrayKeys): array
    {
        $keyCount = 0;
        $lastKey = null;
        $keys = $useArrayKeys ? array_keys($row) : null;

        for ($rowIndex = 0; $rowIndex < $rowCount; ++$rowIndex) {
            $iterable = $keys ?? $row;
            foreach ($iterable as $key => $_value) {
                $lastKey = self::assertStringKey($keys !== null ? $_value : $key);
                ++$keyCount;
            }
        }

        return ['keys' => $keyCount, 'last_key' => $lastKey];
    }

    private static function assertStringKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw new RuntimeException('The attribution row contains a non-string key.');
        }

        return $key;
    }

    /** @return array<array-key, int> */
    private function attributionRow(int $columnCount): array
    {
        $row = [];
        for ($columnIndex = 0; $columnIndex < $columnCount; ++$columnIndex) {
            $row[sprintf('column_%02d', $columnIndex)] = $columnIndex;
        }

        return $row;
    }

    /** @return Scenario */
    private function resultMemory(ScenarioRequest $request): array
    {
        $sizes = match ($request->name->value()) {
            ScenarioName::RESULT_MEMORY_SMALL => ['ci' => 100, 'reference' => 1_000],
            ScenarioName::RESULT_MEMORY_NORMAL => ['ci' => 1_000, 'reference' => 10_000],
            ScenarioName::RESULT_MEMORY_HIGH => ['ci' => 10_000, 'reference' => 50_000],
            default => throw new RuntimeException('Unknown result-memory benchmark dimension.'),
        };
        [$connection, $pdo, $rows, $columns, $rowPayloadBytes] = $this->rowFixture(
            $request,
            false,
            $sizes,
            256,
        );
        $directFull = fn (): array => $this->summarizeAssociativeRows(
            $this->selectRows($pdo, $columns)->fetchAll(PDO::FETCH_ASSOC),
        );
        $simpleQueryFull = fn (): array => $this->summarizeAssociativeRows(
            $this->builder($connection, $columns)->getAssociative(),
        );
        $directCursor = fn (): array => $this->summarizeDirectAssociativeCursor($pdo, $columns);
        $simpleQueryCursor = fn (): array => $this->summarizeSimpleQueryAssociativeCursor($connection, $columns);

        return [
            'operations' => [
                'pdo_full_result' => $directFull,
                'simplequery_full_result' => $simpleQueryFull,
                'pdo_streaming_cursor' => $directCursor,
                'simplequery_streaming_cursor' => $simpleQueryCursor,
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
        $directAssociative = fn (): ?array => $this->fetchFirstPdo($pdo, $columns, PDO::FETCH_ASSOC);
        $simpleQueryAssociative = fn (): ?array => $this->builder($connection, $columns)->firstAssociative();
        $directObject = fn (): ?array => $this->fetchFirstPdo($pdo, $columns, PDO::FETCH_OBJ);
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
        $directAssociative = fn (): array => $this->drainPdo($pdo, $columns, PDO::FETCH_ASSOC);
        $simpleQueryAssociative = function () use ($connection, $columns): array {
            $result = [];
            foreach ($this->builder($connection, $columns)->iterateAssociative() as $row) {
                $result[] = $row;
            }

            return $result;
        };
        $directObjects = fn (): array => $this->drainPdo($pdo, $columns, PDO::FETCH_OBJ);
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
        $directAssociative = fn (): array => $this->closePdoEarly($pdo, $columns, PDO::FETCH_ASSOC);
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
        $directObject = fn (): array => $this->closePdoEarly($pdo, $columns, PDO::FETCH_OBJ);
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
                'first' => $this->fetchFirstPdo($pdo, $columns, PDO::FETCH_ASSOC),
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
    private function rowFixture(
        ScenarioRequest $request,
        bool $wide,
        ?array $sizes = null,
        ?int $payloadBytes = null,
    ): array {
        $sizes ??= $wide
            ? ['ci' => 1_000, 'reference' => 10_000]
            : ['ci' => 1_000, 'reference' => 100_000];
        $rows = $request->scale($sizes);
        $payloadColumns = $wide ? self::WIDE_PAYLOAD_COLUMNS : self::NARROW_PAYLOAD_COLUMNS;
        $payloadBytes ??= $wide ? 24 : 96;
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

    /**
     * @param array<array-key, mixed> $rows
     * @return array{rows: int, id_sum: int, payload_bytes: int, first_id: int|null, last_id: int|null}
     */
    private function summarizeAssociativeRows(array $rows): array
    {
        $summary = $this->emptyRowSummary();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('The benchmark result contains a non-array row.');
            }
            $this->addRowToSummary($summary, $row);
        }

        return $summary;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array{rows: int, id_sum: int, payload_bytes: int, first_id: int|null, last_id: int|null}
     */
    private function summarizeDirectAssociativeCursor(PDO $pdo, array $columns): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $summary = $this->emptyRowSummary();
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The direct benchmark cursor returned a non-array row.');
            }
            $this->addRowToSummary($summary, $row);
        }
        if (!$statement->closeCursor()) {
            throw new RuntimeException('The direct benchmark cursor could not be closed.');
        }

        return $summary;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array{rows: int, id_sum: int, payload_bytes: int, first_id: int|null, last_id: int|null}
     */
    private function summarizeSimpleQueryAssociativeCursor(Connection $connection, array $columns): array
    {
        $summary = $this->emptyRowSummary();
        foreach ($this->builder($connection, $columns)->iterateAssociative() as $row) {
            $this->addRowToSummary($summary, $row);
        }

        return $summary;
    }

    /** @return array{rows: int, id_sum: int, payload_bytes: int, first_id: int|null, last_id: int|null} */
    private function emptyRowSummary(): array
    {
        return ['rows' => 0, 'id_sum' => 0, 'payload_bytes' => 0, 'first_id' => null, 'last_id' => null];
    }

    /**
     * @param array{rows: int, id_sum: int, payload_bytes: int, first_id: int|null, last_id: int|null} $summary
     * @param array<array-key, mixed> $row
     */
    private function addRowToSummary(array &$summary, array $row): void
    {
        $id = $row['id'] ?? null;
        $payload = $row['payload'] ?? null;
        if (!is_int($id) || !is_string($payload)) {
            throw new RuntimeException('The benchmark result row has an invalid shape.');
        }
        ++$summary['rows'];
        $summary['id_sum'] += $id;
        $summary['payload_bytes'] += strlen($payload);
        $summary['first_id'] ??= $id;
        $summary['last_id'] = $id;
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
     * @return array<string, mixed>|null
     */
    private function fetchFirstPdo(PDO $pdo, array $columns, int $fetchMode): ?array
    {
        $row = $this->selectRows($pdo, $columns, true)->fetch($fetchMode);

        return $row === false ? null : self::normalizePdoRow($row);
    }

    /**
     * @param non-empty-list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function drainPdo(PDO $pdo, array $columns, int $fetchMode): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $result = [];
        while (($row = $statement->fetch($fetchMode)) !== false) {
            $result[] = self::normalizePdoRow($row);
        }

        return $result;
    }

    /**
     * @param non-empty-list<string> $columns
     * @return array{first: array<string, mixed>|null, closed: bool}
     */
    private function closePdoEarly(PDO $pdo, array $columns, int $fetchMode): array
    {
        $statement = $this->selectRows($pdo, $columns);
        $row = $statement->fetch($fetchMode);
        $first = $row === false ? null : self::normalizePdoRow($row);

        return ['first' => $first, 'closed' => $statement->closeCursor()];
    }

    /** @return array<string, mixed> */
    private static function normalizePdoRow(mixed $row): array
    {
        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            return $row;
        }
        if (is_object($row)) {
            return get_object_vars($row);
        }

        throw new RuntimeException('The benchmark cursor returned an unexpected row type.');
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
