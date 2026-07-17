<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Testing\CompiledWriteQuery;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class CompilerSmokeTest extends TestCase
{
    private PDO $pdo;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL'
            . ')',
        );
        $this->connection = Connection::fromPdo($this->pdo, Driver::Sqlite);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testCompiledWriteAndSelectExecuteEndToEndAgainstSQLite(): void
    {
        $insert = CompiledWriteQuery::insertMany($this->connection->table('users'), [
            ['name' => 'Ada', 'active' => true],
            ['name' => 'Grace', 'active' => false],
        ]);
        self::assertSame(2, $this->execute($insert));

        $select = $this->connection
            ->table('users')
            ->select('id', 'name')
            ->where('active', true)
            ->orderBy('id')
            ->compile();
        $statement = $this->prepareAndExecute($select);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);

        $update = CompiledWriteQuery::update(
            $this->connection->table('users')->where('name', 'Grace'),
            ['active' => true],
        );
        self::assertSame(1, $this->execute($update));

        $delete = CompiledWriteQuery::delete($this->connection->table('users')->where('id', 1));
        self::assertSame(1, $this->execute($delete));
        $count = $this->pdo->query('SELECT COUNT(*) FROM users');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(1, $count->fetchColumn());
    }

    private function execute(CompiledQuery $query): int
    {
        return $this->prepareAndExecute($query)->rowCount();
    }

    private function prepareAndExecute(CompiledQuery $query): \PDOStatement
    {
        $statement = $this->pdo->prepare($query->sql);
        foreach ($query->bindings as $index => $binding) {
            $statement->bindValue($index + 1, $binding->value, $this->pdoType($binding));
        }
        $statement->execute();

        return $statement;
    }

    private function pdoType(Binding $binding): int
    {
        return match ($binding->type) {
            ParameterType::Null => PDO::PARAM_NULL,
            ParameterType::Integer => PDO::PARAM_INT,
            ParameterType::String, ParameterType::Binary => PDO::PARAM_STR,
            ParameterType::Lob => PDO::PARAM_LOB,
            ParameterType::Auto => throw new \LogicException('Compiled bindings cannot be automatic.'),
        };
    }
}
