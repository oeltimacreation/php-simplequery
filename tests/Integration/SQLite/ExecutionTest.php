<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\QueryBuilder;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class ExecutionTest extends TestCase
{
    private Connection $connection;

    private RecordingQueryObserver $observer;

    #[\Override]
    protected function setUp(): void
    {
        $this->observer = new RecordingQueryObserver();
        $this->connection = Connection::connect(
            Driver::Sqlite,
            'sqlite::memory:',
            connectionOptions: new ConnectionOptions(label: 'integration'),
            observer: $this->observer,
        );
        $this->connection->pdo()->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL UNIQUE, '
            . 'score NUMERIC NULL, '
            . 'active INTEGER NOT NULL, '
            . 'category TEXT NULL'
            . ')',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        try {
            $this->connection->close();
        } catch (TransactionStateException) {
            // A failing cursor assertion retains the resource for PHPUnit diagnostics.
        }
    }

    public function testWriteAndHydrationTerminalsExecuteEndToEnd(): void
    {
        $firstId = $this->connection->table('users')->insertGetId([
            'name' => 'Ada',
            'score' => '10.50',
            'active' => true,
            'category' => 'engineering',
        ]);
        self::assertSame('1', $firstId);

        self::assertSame(1, $this->connection->table('users')->insert([
            'name' => 'Grace',
            'score' => 20,
            'active' => false,
            'category' => 'engineering',
        ]));
        self::assertSame(2, $this->connection->table('users')->insertMany([
            ['name' => 'Linus', 'score' => 30, 'active' => true, 'category' => 'systems'],
            ['name' => 'Margaret', 'score' => null, 'active' => true, 'category' => 'space'],
        ]));

        $objects = $this->connection->table('users')->orderBy('id')->get();
        self::assertCount(4, $objects);
        self::assertContainsOnlyInstancesOf(stdClass::class, $objects);
        $objects[0]->displayName = 'Ada Lovelace';
        self::assertSame('Ada Lovelace', $objects[0]->displayName);

        $associative = $this->connection->table('users')->orderBy('id')->getAssociative();
        self::assertSame('Grace', $associative[1]['name']);
        self::assertSame(1, $associative[0]['active']);

        $builder = $this->connection->table('users')->orderBy('id')->limit(3);
        self::assertSame('Ada', $builder->first()?->name);
        self::assertStringContainsString('LIMIT 3', $builder->compile()->sql);
        self::assertSame('Ada', $builder->firstAssociative()['name'] ?? null);
        self::assertNull($this->connection->table('users')->where('name', 'missing')->first());
        self::assertNull($this->connection->table('users')->where('name', 'missing')->firstAssociative());

        self::assertSame(1, $this->connection->table('users')->where('name', 'Grace')->update([
            'active' => true,
        ]));
        self::assertSame(1, $this->connection->table('users')->where('name', 'Linus')->delete());
        self::assertSame(3, $this->connection->table('users')->count());
    }

    public function testRawQueryTerminalsAreDeferredAndDoNotRewriteFirstSql(): void
    {
        $rawInsert = $this->connection->query(
            'INSERT INTO users (name, score, active, category) VALUES (?, ?, ?, ?)',
            ['Ada', '10.5', true, 'engineering'],
        );
        self::assertSame([], $this->observer->executions());
        self::assertSame(1, $rawInsert->execute());

        $all = $this->connection->query('SELECT id, name FROM users WHERE active = ?', [true]);
        self::assertSame('Ada', $all->get()[0]->name);
        self::assertSame('Ada', $all->getAssociative()[0]['name']);

        $firstSql = 'SELECT id, name FROM users ORDER BY id';
        self::assertSame('Ada', $this->connection->query($firstSql)->first()?->name);
        self::assertSame($firstSql, $this->observer->executions()[3]->sql);
        self::assertSame('Ada', $this->connection->query($firstSql)->firstAssociative()['name'] ?? null);
        self::assertNull($this->connection->query('SELECT id FROM users WHERE id < 0')->first());
    }

    public function testAggregatesPreserveLogicalCountAndDriverScalars(): void
    {
        $this->seedUsers();

        $limited = $this->connection->table('users')->orderBy('id')->limit(1)->offset(1);
        self::assertSame(4, $limited->count());
        $countExecution = $this->lastExecution();
        self::assertStringNotContainsString('ORDER BY', $countExecution->sql);
        self::assertStringNotContainsString('LIMIT', $countExecution->sql);

        self::assertSame(3, $this->connection->table('users')->select('category')->distinct()->count());
        self::assertStringContainsString('SELECT COUNT(*) FROM (SELECT DISTINCT', $this->lastExecution()->sql);

        self::assertSame(3, $this->connection->table('users')->select('category')->groupBy('category')->count());
        self::assertStringContainsString('GROUP BY', $this->lastExecution()->sql);

        $sum = $this->connection->table('users')->sum('score');
        self::assertContains(get_debug_type($sum), ['int', 'float', 'string']);
        self::assertSame(20.166666666666668, $this->connection->table('users')->average('score'));
        self::assertSame(10.5, $this->connection->table('users')->min('score'));
        self::assertSame(30, $this->connection->table('users')->max('score'));
        self::assertNull($this->connection->table('users')->where('id', '<', 0)->sum('score'));
        self::assertNull($this->connection->table('users')->where('id', '<', 0)->average('score'));
    }

    public function testNonCountScalarAggregatesRejectMultiRowAndDistinctShapes(): void
    {
        $this->seedUsers();
        $terminals = [
            'sum' => static fn (QueryBuilder $query): mixed => $query->sum('score'),
            'average' => static fn (QueryBuilder $query): mixed => $query->average('score'),
            'min' => static fn (QueryBuilder $query): mixed => $query->min('score'),
            'max' => static fn (QueryBuilder $query): mixed => $query->max('score'),
        ];
        $builders = [
            'distinct' => fn () => $this->connection->table('users')->distinct(),
            'grouped' => fn () => $this->connection->table('users')->groupBy('category'),
            'having' => fn () => $this->connection->table('users')->having('score', '>', 0),
        ];

        foreach ($terminals as $terminal) {
            foreach ($builders as $builder) {
                try {
                    $terminal($builder());
                    self::fail('An ambiguous non-count scalar aggregate unexpectedly executed.');
                } catch (UnsupportedFeatureException) {
                    self::addToAssertionCount(1);
                }
            }
        }

        self::assertSame(
            40.5,
            $this->connection
                ->table('users', 'u')
                ->join('users', 'users.id', '=', 'u.id')
                ->where('u.active', true)
                ->sum('u.score'),
        );
        self::assertSame(3, $this->connection->table('users')->groupBy('category')->count());
    }

    public function testEveryConcreteBindingTypeIsBoundExplicitly(): void
    {
        $this->connection->pdo()->exec(
            'CREATE TABLE binding_values ('
            . 'null_value TEXT NULL, integer_value INTEGER, string_value TEXT, '
            . 'lob_value BLOB, binary_value BLOB, boolean_value INTEGER, float_value TEXT'
            . ')',
        );
        $lob = fopen('php://temp', 'w+b');
        self::assertIsResource($lob);
        fwrite($lob, 'stream-data');
        rewind($lob);

        self::assertSame(1, $this->connection->query(
            'INSERT INTO binding_values VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                new Binding(null, ParameterType::Null),
                new Binding(7, ParameterType::Integer),
                new Binding('text-data', ParameterType::String),
                new Binding($lob, ParameterType::Lob),
                new Binding("binary\0data", ParameterType::Binary),
                true,
                1.25,
            ],
        )->execute());
        fclose($lob);

        $row = $this->connection->query('SELECT * FROM binding_values')->firstAssociative();
        self::assertNotNull($row);
        self::assertNull($row['null_value']);
        self::assertSame(7, $row['integer_value']);
        self::assertSame('text-data', $row['string_value']);
        self::assertSame('stream-data', $row['lob_value']);
        self::assertSame("binary\0data", $row['binary_value']);
        self::assertSame(1, $row['boolean_value']);
        self::assertSame('1.25', $row['float_value']);
        self::assertSame(
            [
                ParameterType::Null,
                ParameterType::Integer,
                ParameterType::String,
                ParameterType::Lob,
                ParameterType::Binary,
                ParameterType::Integer,
                ParameterType::String,
            ],
            $this->observer->executions()[0]->parameterTypes,
        );
    }

    public function testCursorLifecycleIsOneShotTrackedAndSafeOnEarlyClose(): void
    {
        $this->seedUsers();

        $cursor = $this->connection->query('SELECT * FROM users ORDER BY id')->iterateAssociative();
        $names = [];
        foreach ($cursor as $associativeRow) {
            self::assertIsArray($associativeRow);
            $names[] = $associativeRow['name'];
        }
        self::assertSame(['Ada', 'Grace', 'Linus', 'Margaret'], $names);
        self::assertTrue($cursor->isClosed());

        try {
            foreach ($cursor as $_row) {
            }
            self::fail('A cursor unexpectedly allowed a second iteration.');
        } catch (InvalidQueryException) {
        }

        $early = $this->connection->query('SELECT * FROM users ORDER BY id')->iterate();
        try {
            foreach ($early as $objectRow) {
                self::assertInstanceOf(stdClass::class, $objectRow);
                break;
            }
        } finally {
            $early->close();
        }
        self::assertTrue($early->isClosed());
        $early->close();

        $active = $this->connection->table('users')->iterate();
        try {
            $this->connection->close();
            self::fail('Connection close unexpectedly truncated an active cursor.');
        } catch (TransactionStateException) {
            self::assertFalse($active->isClosed());
        }
        $active->close();
        $this->connection->close();

        $this->expectException(ConnectionException::class);
        $this->connection->query('SELECT 1')->get();
    }

    public function testGeneratorCleanupClosesAbandonedCursor(): void
    {
        $this->seedUsers();
        $cursor = $this->connection->table('users')->iterate();
        $iterator = $cursor->getIterator();
        self::assertInstanceOf(\Generator::class, $iterator);
        $iterator->rewind();
        self::assertTrue($iterator->valid());
        unset($iterator);
        gc_collect_cycles();

        self::assertTrue($cursor->isClosed());
        $this->connection->close();
    }

    public function testExecutionFailureIsConvertedAndObservedWithoutValues(): void
    {
        $this->connection->table('users')->insert([
            'name' => 'secret-person-name',
            'score' => null,
            'active' => true,
            'category' => null,
        ]);

        try {
            $this->connection->table('users')->insert([
                'name' => 'secret-person-name',
                'score' => null,
                'active' => true,
                'category' => null,
            ]);
            self::fail('The duplicate unique value unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('23000', $exception->sqlState);
            self::assertNotNull($exception->driverCode);
            self::assertSame(Driver::Sqlite, $exception->driver);
            self::assertSame('integration', $exception->connectionLabel);
            self::assertStringContainsString('INSERT INTO "users"', $exception->sql);
            self::assertStringNotContainsString('secret-person-name', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertCount(2, $this->observer->executions());
        self::assertFalse($this->lastExecution()->successful);
        self::assertNull($this->lastExecution()->affectedRows);
    }

    public function testObserverFailuresCannotReplaceDatabaseOutcomes(): void
    {
        $observer = new class implements QueryObserver {
            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
                throw new \RuntimeException('observer failure');
            }
        };
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $observer);

        self::assertSame(1, $connection->query('SELECT 1 AS value')->firstAssociative()['value'] ?? null);

        try {
            $connection->query('SELECT * FROM missing_table')->get();
            self::fail('An invalid query unexpectedly succeeded.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('HY000', $exception->sqlState);
        }
        $connection->close();
    }

    public function testGeneratedIdIsCapturedBeforeObserverCodeCanRunAnotherStatement(): void
    {
        $observer = new class implements QueryObserver {
            public ?\PDO $pdo = null;

            private bool $inserted = false;

            #[\Override]
            public function queryExecuted(QueryExecution $execution): void
            {
                if (!$execution->successful || !str_starts_with($execution->sql, 'INSERT') || $this->inserted) {
                    return;
                }
                $this->inserted = true;
                $this->pdo?->exec("INSERT INTO generated_ids (label) VALUES ('observer-row')");
            }
        };
        $connection = Connection::connect(Driver::Sqlite, 'sqlite::memory:', observer: $observer);
        $connection->pdo()->exec(
            'CREATE TABLE generated_ids (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)',
        );
        $observer->pdo = $connection->pdo();

        $id = $connection->table('generated_ids')->insertGetId(['label' => 'terminal-row']);

        self::assertSame('1', $id);
        self::assertSame(2, $connection->table('generated_ids')->count());
        $connection->close();
    }

    public function testObserverReportsMeaningfulAffectedRowsAndPhysicalTransactionDepth(): void
    {
        $this->connection->table('users')->insert([
            'name' => 'Ada',
            'score' => null,
            'active' => true,
            'category' => null,
        ]);
        self::assertSame(1, $this->lastExecution()->affectedRows);
        self::assertSame(0, $this->lastExecution()->transactionDepth);

        $this->connection->pdo()->beginTransaction();
        $this->connection->query('SELECT id FROM users')->get();
        self::assertNull($this->lastExecution()->affectedRows);
        self::assertSame(1, $this->lastExecution()->transactionDepth);
        $this->connection->pdo()->rollBack();
    }

    public function testGeneratedIdTerminalRejectsTablesWithoutGeneratedIdentity(): void
    {
        $this->connection->pdo()->exec('CREATE TABLE natural_keys (code TEXT PRIMARY KEY) WITHOUT ROWID');

        $this->expectException(QueryExecutionException::class);
        $this->connection->table('natural_keys')->insertGetId(['code' => 'key']);
    }

    private function seedUsers(): void
    {
        $this->connection->table('users')->insertMany([
            ['name' => 'Ada', 'score' => '10.5', 'active' => true, 'category' => 'engineering'],
            ['name' => 'Grace', 'score' => 20, 'active' => false, 'category' => 'engineering'],
            ['name' => 'Linus', 'score' => 30, 'active' => true, 'category' => 'systems'],
            ['name' => 'Margaret', 'score' => null, 'active' => true, 'category' => 'space'],
        ]);
        $this->observer->clear();
    }

    private function lastExecution(): QueryExecution
    {
        $executions = $this->observer->executions();

        return $executions[count($executions) - 1];
    }
}
