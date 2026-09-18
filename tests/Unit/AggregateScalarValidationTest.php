<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Scalar aggregates preserve driver scalars, and reject anything else.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class AggregateScalarValidationTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ConfigurableStatement::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ConfigurableStatement::reset();
    }

    #[DataProvider('scalarTerminals')]
    public function testScalarAggregatesPreserveSupportedDriverValues(string $terminal, mixed $value): void
    {
        $db = $this->connection();
        ConfigurableStatement::returnsColumn($value);

        self::assertSame($value, $this->aggregate($db, $terminal));
        $db->close();
    }

    #[DataProvider('scalarTerminals')]
    public function testScalarAggregatesRejectUnsupportedDriverValues(string $terminal): void
    {
        $db = $this->connection();
        ConfigurableStatement::returnsColumn(true);

        try {
            $this->aggregate($db, $terminal);
            self::fail('An unsupported aggregate scalar was returned.');
        } catch (QueryExecutionException $failure) {
            self::assertStringContainsString('unsupported scalar type', $failure->getMessage());
        }
        $db->close();
    }

    private function aggregate(Connection $connection, string $terminal): mixed
    {
        return match ($terminal) {
            'sum' => $connection->table('items')->sum('value'),
            'average' => $connection->table('items')->average('value'),
            'min' => $connection->table('items')->min('value'),
            'max' => $connection->table('items')->max('value'),
            default => throw new \InvalidArgumentException('Unknown aggregate terminal.'),
        };
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function scalarTerminals(): iterable
    {
        foreach (['sum', 'average', 'min', 'max'] as $terminal) {
            yield $terminal . ' integer' => [$terminal, 42];
            yield $terminal . ' float' => [$terminal, 1.25];
            yield $terminal . ' decimal string' => [$terminal, '1234567890.1234567890'];
            yield $terminal . ' null' => [$terminal, null];
        }
    }

    private function connection(): Connection
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_STATEMENT_CLASS => [ConfigurableStatement::class]]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');

        return Connection::fromPdo($pdo, Driver::Sqlite);
    }
}
