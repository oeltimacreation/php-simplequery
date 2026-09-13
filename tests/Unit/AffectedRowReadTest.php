<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Testing\RecordingQueryObserver;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class AffectedRowReadTest extends TestCase
{
    #[DataProvider('observationModes')]
    public function testRequiredCountsAreReadOnce(bool $observed): void
    {
        [$db, $observer] = $this->connection($observed);
        ConfigurableStatement::$rowCountThrowsOnCall = 2;
        self::assertSame(1, $db->table('items')->insert(['value' => 'one']));
        self::assertSame(1, ConfigurableStatement::$rowCountCalls);
        self::assertSame(1, ConfigurableStatement::$closeCalls);
        self::assertCount($observed ? 1 : 0, $observer->executions());
        if ($observed) {
            self::assertSame(1, $observer->executions()[0]->affectedRows);
        }
        $db->close();
    }

    #[DataProvider('observationModes')]
    public function testGeneratedIdsOnlyReadCountsForObservation(bool $observed): void
    {
        [$db, $observer] = $this->connection($observed);
        ConfigurableStatement::$rowCountThrowsOnCall = $observed ? 2 : 1;
        self::assertSame('1', $db->table('items')->insertGetId(['value' => 'one']));
        self::assertSame($observed ? 1 : 0, ConfigurableStatement::$rowCountCalls);
        self::assertCount($observed ? 1 : 0, $observer->executions());
        if ($observed) {
            self::assertSame(1, $observer->executions()[0]->affectedRows);
        }
        $db->close();
    }

    public function testRequiredCountFailureStillReportsAnAlreadyExecutedWrite(): void
    {
        [$db, $observer] = $this->connection(true);
        ConfigurableStatement::$rowCountThrowsOnCall = 1;
        try {
            $db->table('items')->insert(['value' => 'one']);
            self::fail('Required count failure was ignored.');
        } catch (QueryExecutionException $exception) {
            self::assertSame('Controlled affected-row failure.', $exception->getPrevious()?->getMessage());
        }
        self::assertSame(1, ConfigurableStatement::$rowCountCalls);
        self::assertSame(1, ConfigurableStatement::$closeCalls);
        self::assertCount(1, $observer->executions());
        self::assertFalse($observer->executions()[0]->successful);
        self::assertNull($observer->executions()[0]->affectedRows);
        self::assertSame(1, $db->table('items')->count());
        $db->close();
    }

    /** @return iterable<string, array{bool}> */
    public static function observationModes(): iterable
    {
        yield 'disabled' => [false];
        yield 'enabled' => [true];
    }

    /** @return array{Connection, RecordingQueryObserver} */
    private function connection(bool $observed): array
    {
        ConfigurableStatement::reset();
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_STATEMENT_CLASS => [ConfigurableStatement::class]]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)');
        $observer = new RecordingQueryObserver();

        return [Connection::fromPdo($pdo, Driver::Sqlite, observer: $observed ? $observer : null), $observer];
    }
}
