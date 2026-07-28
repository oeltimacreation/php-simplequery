<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\QueryBuilder;
use PDO;
use PDOStatement;
use RuntimeException;

final class ProductionWorkloadScenarios implements ScenarioFactory
{
    #[\Override]
    public function prepare(ScenarioRequest $request): ?PreparedScenario
    {
        return match ($request->name->value()) {
            ScenarioName::PRODUCTION_REPORT_EXECUTE => $this->report($request),
            ScenarioName::PRODUCTION_BATCH_EXECUTE => $this->batch($request),
            default => null,
        };
    }

    private function report(ScenarioRequest $request): PreparedScenario
    {
        $fixtureRows = $request->scale(['ci' => 2_000, 'reference' => 50_000]);
        $connection = $this->reportFixture($fixtureRows);
        $pdo = $connection->pdo();

        return new PreparedScenario(
            $this->reportOperations($connection, $pdo),
            $pdo,
            ['fixture_rows' => $fixtureRows, 'projection_columns' => 7, 'read_modes' => 5],
        );
    }

    /** @return array<non-empty-string, \Closure(): mixed> */
    private function reportOperations(Connection $connection, PDO $pdo): array
    {
        return [
            'object_hydration' => fn (): array => $this->objectReport($connection),
            'associative_hydration' => fn (): array => $this->associativeReport($connection),
            'object_cursor' => fn (): array => $this->objectCursorReport($connection),
            'associative_cursor' => fn (): array => $this->associativeCursorReport($connection),
            'pdo_bulk_read' => fn (): array => $this->pdoReport($pdo),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, count: int} */
    private function objectReport(Connection $connection): array
    {
        $rows = array_map(
            static fn (object $row): array => get_object_vars($row),
            $this->reportQuery($connection)->get(),
        );

        return $this->reportResult($rows, $this->reportQuery($connection)->count());
    }

    /** @return array{rows: list<array<string, mixed>>, count: int} */
    private function associativeReport(Connection $connection): array
    {
        return $this->reportResult(
            $this->reportQuery($connection)->getAssociative(),
            $this->reportQuery($connection)->count(),
        );
    }

    /** @return array{rows: list<array<string, mixed>>, count: int} */
    private function objectCursorReport(Connection $connection): array
    {
        $rows = [];
        foreach ($this->reportQuery($connection)->iterate() as $row) {
            $rows[] = get_object_vars($row);
        }

        return $this->reportResult($rows, $this->reportQuery($connection)->count());
    }

    /** @return array{rows: list<array<string, mixed>>, count: int} */
    private function associativeCursorReport(Connection $connection): array
    {
        $rows = [];
        foreach ($this->reportQuery($connection)->iterateAssociative() as $row) {
            $rows[] = $row;
        }

        return $this->reportResult($rows, $this->reportQuery($connection)->count());
    }

    /** @return array{rows: list<array<string, mixed>>, count: int} */
    private function pdoReport(PDO $pdo): array
    {
        $bindings = self::reportBindings();
        $rowsStatement = $this->prepareStatement($pdo, self::reportSql());
        $rowsStatement->execute($bindings);
        $countStatement = $this->prepareStatement($pdo, 'SELECT COUNT(*) ' . self::reportSqlFromAndWhere());
        $countStatement->execute($bindings);

        return $this->reportResult(
            array_values($rowsStatement->fetchAll(PDO::FETCH_ASSOC)),
            (int) $countStatement->fetchColumn(),
        );
    }

    private function reportQuery(Connection $connection): QueryBuilder
    {
        return $connection
            ->table('production_events', 'e')
            ->select(
                'e.id',
                'e.account_id',
                Identifier::of('a.name')->as('account_name'),
                'e.status',
                'e.score',
                'e.created_at',
                'e.payload',
            )
            ->join(Identifier::of('production_accounts')->as('a'), 'a.id', '=', 'e.account_id')
            ->where('e.created_at', '>=', '2026-01-10 00:00:00')
            ->where('e.created_at', '<', '2026-01-20 00:00:00')
            ->where('e.status', '=', 'ready')
            ->where('e.score', '>=', 40)
            ->whereIn('e.account_id', range(1, 10))
            ->orderBy('e.id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, count: int}
     */
    private function reportResult(array $rows, int $count): array
    {
        if (count($rows) !== $count) {
            throw new RuntimeException('Production report count does not match its hydrated result.');
        }

        return ['rows' => $rows, 'count' => $count];
    }

    private function reportFixture(int $rows): Connection
    {
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE production_accounts (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec(
            'CREATE TABLE production_events ('
            . 'id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, status TEXT NOT NULL, '
            . 'score INTEGER NOT NULL, created_at TEXT NOT NULL, payload TEXT NOT NULL)',
        );
        $this->insertAccounts($connection);
        $this->insertEvents($connection, $rows);

        return $connection;
    }

    private function insertAccounts(Connection $connection): void
    {
        $accounts = [];
        for ($id = 1; $id <= 20; ++$id) {
            $accounts[] = ['id' => $id, 'name' => 'Account ' . $id];
        }
        $connection->table('production_accounts')->insertMany($accounts);
    }

    private function insertEvents(Connection $connection, int $rows): void
    {
        $events = [];
        for ($id = 1; $id <= $rows; ++$id) {
            $events[] = [
                'id' => $id,
                'account_id' => (($id - 1) % 20) + 1,
                'status' => $id % 3 === 0 ? 'ready' : 'pending',
                'score' => $id % 100,
                'created_at' => sprintf('2026-01-%02d 12:00:00', (($id - 1) % 28) + 1),
                'payload' => str_repeat((string) ($id % 10), 64),
            ];
        }
        $connection->table('production_events')->insertMany($events);
    }

    private function batch(ScenarioRequest $request): PreparedScenario
    {
        $rows = $request->scale(['ci' => 100, 'reference' => 1_000]);
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->exec(
            'CREATE TABLE production_batch (id INTEGER PRIMARY KEY, label TEXT NOT NULL, enabled INTEGER NOT NULL)',
        );
        $fixture = $this->batchFixture($rows);

        return new PreparedScenario(
            [
                'insert_many' => fn (): array => $this->insertMany($connection, $fixture),
                'repeated_insert' => fn (): array => $this->insertRepeated($connection, $fixture),
            ],
            $pdo,
            ['rows' => $rows, 'columns' => 3],
        );
    }

    /** @return list<array{id: int, label: string, enabled: bool}> */
    private function batchFixture(int $rows): array
    {
        $fixture = [];
        for ($id = 1; $id <= $rows; ++$id) {
            $fixture[] = ['id' => $id, 'label' => 'Batch ' . $id, 'enabled' => $id % 2 === 0];
        }

        return $fixture;
    }

    /**
     * @param list<array{id: int, label: string, enabled: bool}> $fixture
     * @return array{affected: int, rows: list<array<string, mixed>>}
     */
    private function insertMany(Connection $connection, array $fixture): array
    {
        return $connection->transaction(function (Connection $database) use ($fixture): array {
            $affected = $database->table('production_batch')->insertMany($fixture);

            return $this->batchResult($database, $affected);
        });
    }

    /**
     * @param list<array{id: int, label: string, enabled: bool}> $fixture
     * @return array{affected: int, rows: list<array<string, mixed>>}
     */
    private function insertRepeated(Connection $connection, array $fixture): array
    {
        return $connection->transaction(function (Connection $database) use ($fixture): array {
            $affected = 0;
            foreach ($fixture as $row) {
                $affected += $database->table('production_batch')->insert($row);
            }

            return $this->batchResult($database, $affected);
        });
    }

    /** @return array{affected: int, rows: list<array<string, mixed>>} */
    private function batchResult(Connection $connection, int $affected): array
    {
        $rows = $connection->table('production_batch')->orderBy('id')->getAssociative();
        $connection->query('DELETE FROM production_batch')->execute();

        return ['affected' => $affected, 'rows' => $rows];
    }

    /** @return list<int|string> */
    private static function reportBindings(): array
    {
        return ['2026-01-10 00:00:00', '2026-01-20 00:00:00', 'ready', 40, ...range(1, 10)];
    }

    private static function reportSql(): string
    {
        return 'SELECT e.id, e.account_id, a.name AS account_name, e.status, e.score, e.created_at, e.payload '
            . self::reportSqlFromAndWhere() . ' ORDER BY e.id ASC';
    }

    private static function reportSqlFromAndWhere(): string
    {
        return 'FROM production_events AS e INNER JOIN production_accounts AS a ON a.id = e.account_id '
            . 'WHERE e.created_at >= ? AND e.created_at < ? AND e.status = ? AND e.score >= ? '
            . 'AND e.account_id IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    }

    private function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Production report statement preparation failed.');
        }

        return $statement;
    }
}
