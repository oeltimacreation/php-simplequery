<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Migration;

use Oeltima\SimpleQuery\ConditionGroup;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\JoinClause;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class RepresentativeSlicesTest extends TestCase
{
    public function testEmbeddedImporterCrudSlicePreservesApplicationVisibleResults(): void
    {
        $connection = $this->connection();
        $connection->query(
            'CREATE TABLE import_records ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, external_key TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL)',
        )->execute();

        $allowedTables = ['import_records'];
        $requestedTable = 'import_records';
        $table = $this->allowlistedTable($requestedTable, $allowedTables);
        $generatedId = $connection->table($table)->insertGetId([
            'external_key' => 'synthetic-001',
            'enabled' => true,
        ]);
        self::assertSame('1', $generatedId);

        $row = $connection->table($table)->where('id', (int) $generatedId)->first();
        self::assertNotNull($row);
        self::assertSame('synthetic-001', $row->external_key);
        $row->display = 'Synthetic 001';
        self::assertSame('Synthetic 001', $row->display);
        self::assertNull($connection->table($table)->where('external_key', 'missing')->first());
        self::assertSame(1, $connection->table($table)->where('id', 1)->update(['enabled' => false]));
        self::assertSame(1, $connection->table($table)->where('id', 1)->delete());
        self::assertSame(0, $connection->table($table)->count());
        $connection->close();
    }

    public function testInjectedSharedModelAndDirectPdoSlicePreserveOwnershipAndJoinShape(): void
    {
        $pdo = $this->pdo();
        $connection = Connection::fromPdo(
            $pdo,
            Driver::Sqlite,
            new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 5000, label: 'synthetic-shared-model'),
        );
        $connection->query(
            'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        )->execute();
        $connection->query(
            'CREATE TABLE staff (id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER NOT NULL, '
            . 'name TEXT NOT NULL, active INTEGER NOT NULL)',
        )->execute();
        $connection->table('departments')->insert(['id' => 1, 'name' => 'Engineering']);

        $pdo->beginTransaction();
        $connection->table('staff')->insert([
            'department_id' => 1,
            'name' => 'Ada',
            'active' => true,
        ]);
        self::assertTrue($pdo->inTransaction());
        $pdo->commit();

        $row = $connection
            ->table('staff', 's')
            ->select('s.id', $connection->raw('UPPER(s.name) AS display_name'), 'd.name')
            ->join(Identifier::of('departments')->as('d'), 'd.id', '=', 's.department_id')
            ->where('s.active', true)
            ->firstAssociative();
        self::assertSame(['id' => 1, 'display_name' => 'ADA', 'name' => 'Engineering'], $row);

        foreach ([Driver::MariaDb, Driver::MySql] as $driver) {
            $compiler = CompilerConnection::for($driver);
            $compiled = $compiler
                ->table('staff', 's')
                ->select('s.id', $compiler->raw('UPPER(s.name) AS display_name'), 'd.name')
                ->join(Identifier::of('departments')->as('d'), 'd.id', '=', 's.department_id')
                ->where('s.active', true)
                ->compile();
            self::assertSame(
                'SELECT `s`.`id`, UPPER(s.name) AS display_name, `d`.`name` FROM `staff` AS `s` '
                . 'INNER JOIN `departments` AS `d` ON `d`.`id` = `s`.`department_id` WHERE `s`.`active` = ?',
                $compiled->sql,
            );
            self::assertSame([1], array_map(static fn ($binding) => $binding->value, $compiled->bindings));
        }
        $connection->close();
    }

    public function testRawReportingSliceUsesDeferredPositionalQueriesAndVendorExpressions(): void
    {
        $observer = new RecordingQueryObserver();
        $connection = $this->connection($observer);
        $connection->query(
            'CREATE TABLE report_events (id INTEGER PRIMARY KEY, category TEXT NOT NULL, amount NUMERIC NOT NULL)',
        )->execute();
        $connection->table('report_events')->insertMany([
            ['id' => 1, 'category' => 'paid', 'amount' => 10],
            ['id' => 2, 'category' => 'paid', 'amount' => 15],
            ['id' => 3, 'category' => 'void', 'amount' => 99],
        ]);
        $observer->clear();

        $report = $connection->query(
            'SELECT category, SUM(amount) AS total FROM report_events '
            . 'WHERE category = ? GROUP BY category',
            ['paid'],
        );
        self::assertSame([], $observer->executions());
        $observer->clear();
        self::assertSame('paid', $report->first()?->category);
        self::assertContains($report->firstAssociative()['total'] ?? null, [25, 25.0, '25']);
        self::assertCount(2, $observer->executions());

        foreach ([Driver::MariaDb, Driver::MySql] as $driver) {
            $compiler = CompilerConnection::for($driver);
            $compiled = $compiler
                ->table('events')
                ->select(
                    $compiler->raw("DATE_FORMAT(created_at, '%Y-%m') AS period"),
                    $compiler->raw('COUNT(*) AS total'),
                )
                ->where('kind', 'login')
                ->groupBy($compiler->raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->compile();
            self::assertSame(['login'], array_map(
                static fn ($binding) => $binding->value,
                $compiled->bindings,
            ));
        }
        $connection->close();
    }

    public function testDiagnosticRawJoinSliceUsesCompilationAndRedactedObserverMetadata(): void
    {
        $observer = new RecordingQueryObserver();
        $connection = $this->connection($observer);
        $connection->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)')->execute();
        $connection->query(
            'CREATE TABLE assignments (id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT)',
        )->execute();
        $connection->table('users')->insert(['id' => 1, 'name' => 'Ada', 'active' => true]);
        $connection->table('assignments')->insert(['id' => 1, 'user_id' => 1, 'kind' => 'owner']);
        $observer->clear();

        $query = $connection
            ->table('users', 'u')
            ->select($connection->raw('? AS report_marker', ['synthetic-report']), 'u.name')
            ->join(
                Identifier::of('assignments')->as('a'),
                static function (JoinClause $join) use ($connection): void {
                    $join->on($connection->raw('a.user_id = u.id AND a.kind = ?', ['owner']));
                },
            )
            ->where('u.active', true);
        $compiled = $query->compile();
        self::assertSame([], $observer->executions());
        $observer->clear();
        self::assertSame(
            'SELECT ? AS report_marker, "u"."name" FROM "users" AS "u" '
            . 'INNER JOIN "assignments" AS "a" ON a.user_id = u.id AND a.kind = ? WHERE "u"."active" = ?',
            $compiled->sql,
        );
        self::assertSame(
            ['synthetic-report', 'owner', 1],
            array_map(static fn ($binding) => $binding->value, $compiled->bindings),
        );

        $rows = $query->getAssociative();
        self::assertSame([['report_marker' => 'synthetic-report', 'name' => 'Ada']], $rows);
        self::assertCount(1, $observer->executions());
        self::assertSame(
            [ParameterType::String, ParameterType::String, ParameterType::Integer],
            $observer->executions()[0]->parameterTypes,
        );
        self::assertObjectNotHasProperty('bindings', $observer->executions()[0]);
        $connection->close();
    }

    public function testComplexListSliceMatchesDirectPdoQueryAndResultSemantics(): void
    {
        $connection = $this->connection();
        $connection->query('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)')->execute();
        $connection->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL)')->execute();
        $connection->query(
            'CREATE TABLE members (id INTEGER PRIMARY KEY, team_id INTEGER, role_id INTEGER, '
            . 'name TEXT, status TEXT, active INTEGER)',
        )->execute();
        $connection->table('teams')->insertMany([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
        ]);
        $connection->table('roles')->insertMany([
            ['id' => 1, 'name' => 'Admin'],
            ['id' => 2, 'name' => 'Reader'],
        ]);
        $connection->table('members')->insertMany([
            ['id' => 1, 'team_id' => 1, 'role_id' => 1, 'name' => 'Ada', 'status' => 'ready', 'active' => true],
            ['id' => 2, 'team_id' => 1, 'role_id' => 2, 'name' => 'Grace', 'status' => 'pending', 'active' => false],
            ['id' => 3, 'team_id' => 2, 'role_id' => null, 'name' => 'Linus', 'status' => 'ready', 'active' => true],
            [
                'id' => 4,
                'team_id' => 2,
                'role_id' => 2,
                'name' => 'Margaret',
                'status' => 'archived',
                'active' => false,
            ],
        ]);

        $query = $connection
            ->table('members', 'm')
            ->select(
                'm.id',
                Identifier::of('m.name')->as('member_name'),
                Identifier::of('t.name')->as('team_name'),
                Identifier::of('r.name')->as('role_name'),
            )
            ->join(Identifier::of('teams')->as('t'), 't.id', '=', 'm.team_id')
            ->leftJoin(Identifier::of('roles')->as('r'), 'r.id', '=', 'm.role_id')
            ->where(static function (ConditionGroup $group): void {
                $group->where('m.active', true)->orWhere('m.status', 'pending');
            })
            ->whereIn('m.team_id', [1, 2])
            ->orderBy('m.id')
            ->limit(2);
        $nativeRows = $query->getAssociative();
        self::assertSame(3, $query->count());

        $statement = $connection->pdo()->prepare(
            'SELECT m.id, m.name AS member_name, t.name AS team_name, r.name AS role_name FROM members m '
            . 'INNER JOIN teams t ON t.id = m.team_id '
            . 'LEFT JOIN roles r ON r.id = m.role_id '
            . 'WHERE (m.active = ? OR m.status = ?) AND m.team_id IN (?, ?) ORDER BY m.id LIMIT 2',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute([1, 'pending', 1, 2]);
        $pdoRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($pdoRows, $nativeRows);
        self::assertSame(['Ada', 'Grace'], array_column($nativeRows, 'member_name'));
        self::assertStringContainsString('LEFT JOIN', $query->compile()->sql);
        $connection->close();
    }

    private function connection(?RecordingQueryObserver $observer = null): Connection
    {
        return Connection::fromPdo(
            $this->pdo(),
            Driver::Sqlite,
            new ConnectionOptions(sqliteBusyTimeoutMilliseconds: 5000, label: 'synthetic-migration'),
            $observer,
        );
    }

    /** @param list<string> $allowedTables */
    private function allowlistedTable(string $requestedTable, array $allowedTables): Identifier
    {
        if (!in_array($requestedTable, $allowedTables, true)) {
            throw new \InvalidArgumentException('The requested synthetic table is not allowed.');
        }

        return Identifier::of($requestedTable);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}
