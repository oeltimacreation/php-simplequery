<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use PDO;
use PDOStatement;
use RuntimeException;

final class QueryPlanEvidence
{
    /** @return array<string, mixed> */
    public static function collect(int $fixtureRows = 20_000): array
    {
        $connection = self::fixture($fixtureRows);
        $range = $connection
            ->table('plan_events')
            ->select('id')
            ->where('created_at', '>=', '2026-01-10 00:00:00')
            ->where('created_at', '<', '2026-01-11 00:00:00')
            ->compile();
        $wrapped = $connection
            ->table('plan_events')
            ->select('id')
            ->where($connection->raw('date(created_at)'), '=', '2026-01-10')
            ->compile();

        return self::report($connection->pdo(), $range, $wrapped, $fixtureRows);
    }

    private static function fixture(int $rows): Connection
    {
        if ($rows < 100) {
            throw new RuntimeException('Query-plan evidence requires at least 100 fixture rows.');
        }
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec(
            'CREATE TABLE plan_events (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, payload TEXT NOT NULL)',
        );
        $pdo->exec('CREATE INDEX index_plan_events_created_at ON plan_events (created_at)');
        $insert = self::prepare($pdo, 'INSERT INTO plan_events (id, created_at, payload) VALUES (?, ?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= $rows; ++$id) {
            $insert->execute([
                $id,
                sprintf('2026-01-%02d %02d:00:00', (($id - 1) % 28) + 1, $id % 24),
                str_repeat((string) ($id % 10), 32),
            ]);
        }
        $pdo->commit();

        return $connection;
    }

    /** @return array<string, mixed> */
    private static function report(PDO $pdo, CompiledQuery $range, CompiledQuery $wrapped, int $rows): array
    {
        $rangePlan = self::plan($pdo, $range);
        $wrappedPlan = self::plan($pdo, $wrapped);
        $rangeResult = self::result($pdo, $range);
        $wrappedResult = self::result($pdo, $wrapped);
        if ($rangeResult !== $wrappedResult) {
            throw new RuntimeException('SARGable and function-wrapped fixtures returned different rows.');
        }
        if (!str_contains(implode(' ', $rangePlan), 'USING COVERING INDEX index_plan_events_created_at')) {
            throw new RuntimeException('The range predicate did not use the ordinary created_at index.');
        }
        if (!str_contains(implode(' ', $wrappedPlan), 'SCAN plan_events')) {
            throw new RuntimeException('The function-wrapped predicate did not demonstrate the expected scan.');
        }

        return [
            'schema_version' => 1,
            'evidence' => 'sqlite-sargable-versus-function-wrapped-query-plan',
            'fixture_rows' => $rows,
            'result_rows' => count($rangeResult),
            'result_digest' => Harness::digest($rangeResult),
            'range' => ['sql' => $range->sql, 'plan' => $rangePlan],
            'function_wrapped' => ['sql' => $wrapped->sql, 'plan' => $wrappedPlan],
            'interpretation' => 'Query plans explain this fixture only; applications own indexes and plan validation.',
        ];
    }

    /** @return list<string> */
    private static function plan(PDO $pdo, CompiledQuery $query): array
    {
        $statement = self::prepare($pdo, 'EXPLAIN QUERY PLAN ' . $query->sql);
        $statement->execute(self::values($query));

        return array_values(array_map(
            static fn (array $row): string => (string) $row['detail'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<int> */
    private static function result(PDO $pdo, CompiledQuery $query): array
    {
        $statement = self::prepare($pdo, $query->sql);
        $statement->execute(self::values($query));

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<mixed> */
    private static function values(CompiledQuery $query): array
    {
        return array_map(static fn (Binding $binding): mixed => $binding->value, $query->bindings);
    }

    private static function prepare(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Query-plan statement preparation failed.');
        }

        return $statement;
    }
}
