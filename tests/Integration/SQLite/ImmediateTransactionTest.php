<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\TransactionMode;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('pdo_sqlite')]
final class ImmediateTransactionTest extends TestCase
{
    private string $databasePath;

    /** @var list<Connection> */
    private array $connections = [];

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'simplequery-immediate-');
        if (!is_string($path)) {
            throw new RuntimeException('Could not allocate an SQLite transaction fixture.');
        }

        $this->databasePath = $path;
        $this->connect()->pdo()->exec(
            'CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL UNIQUE)',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $pdo = $connection->pdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $connection->close();
            } catch (\Throwable) {
            }
        }

        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testImmediateModeCommitsWithNestedSavepointsAndImmediateGeneratedId(): void
    {
        $connection = $this->connections[0];

        $generatedId = $connection->transaction(
            function (Connection $database): string {
                self::assertTrue($database->pdo()->inTransaction());
                $id = $database->table('records')->insertGetId(['label' => 'outer']);

                $failure = new RuntimeException('roll back nested work');
                try {
                    $database->transaction(function (Connection $nested) use ($failure): never {
                        $nested->table('records')->insert(['label' => 'nested-rollback']);
                        throw $failure;
                    });
                } catch (RuntimeException $caught) {
                    self::assertSame($failure, $caught);
                }

                return $id;
            },
            TransactionMode::Immediate,
        );

        self::assertSame('1', $generatedId);
        self::assertFalse($connection->pdo()->inTransaction());
        self::assertSame(['outer'], array_column($connection->table('records')->getAssociative(), 'label'));
    }

    public function testImmediateModeRetainsCallbackExceptionIdentityAndAllowsReuse(): void
    {
        $connection = $this->connections[0];
        $failure = new RuntimeException('domain failure');

        try {
            $connection->transaction(function (Connection $database) use ($failure): never {
                $database->table('records')->insert(['label' => 'rolled-back']);
                throw $failure;
            }, TransactionMode::Immediate);
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $connection->transaction(
            static fn (Connection $database): int => $database->table('records')->insert(['label' => 'reused']),
            TransactionMode::Immediate,
        );

        self::assertSame(['reused'], array_column($connection->table('records')->getAssociative(), 'label'));
    }

    public function testBusyImmediateBeginRetainsDriverEvidenceAndConnectionCanBeReused(): void
    {
        $first = $this->connections[0];
        $second = $this->connect(25);
        $beginFailure = null;

        $first->transaction(
            function (Connection $database) use ($second, &$beginFailure): void {
                $database->table('records')->insert(['label' => 'first-writer']);

                try {
                    $second->transaction(static fn (): null => null, TransactionMode::Immediate);
                } catch (TransactionException $exception) {
                    $beginFailure = $exception;
                }
            },
            TransactionMode::Immediate,
        );

        self::assertInstanceOf(TransactionException::class, $beginFailure);
        self::assertSame('begin', $beginFailure->operation);
        self::assertFalse($beginFailure->connectionUnusable);
        self::assertInstanceOf(PDOException::class, $beginFailure->controlFailure);
        self::assertSame('HY000', $beginFailure->controlFailure->errorInfo[0] ?? null);
        self::assertSame(5, $beginFailure->controlFailure->errorInfo[1] ?? null);

        $second->transaction(
            static fn (Connection $database): int => $database->table('records')->insert(['label' => 'second-writer']),
            TransactionMode::Immediate,
        );
        self::assertSame(2, $second->table('records')->count());
    }

    public function testManualImmediateTransactionRemainsExternallyOwned(): void
    {
        $connection = $this->connections[0];
        $pdo = $connection->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        self::assertTrue($pdo->inTransaction());

        try {
            $connection->transaction(static fn (): null => null);
            self::fail('The externally started immediate transaction was unexpectedly adopted.');
        } catch (ExternalTransactionException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }

        self::assertFalse($pdo->inTransaction());
    }

    public function testNestedModeSelectionIsRejectedWithoutEndingOuterScope(): void
    {
        $connection = $this->connections[0];

        $connection->transaction(
            function (Connection $database): void {
                try {
                    $database->transaction(static fn (): null => null, TransactionMode::Immediate);
                    self::fail('A nested physical mode selection unexpectedly succeeded.');
                } catch (InvalidQueryException) {
                    self::assertSame(1, $database->transactionDepth());
                }

                $database->table('records')->insert(['label' => 'outer-continues']);
            },
            TransactionMode::Immediate,
        );

        self::assertSame(1, $connection->table('records')->count());
    }

    public function testLiveCursorStillBlocksImmediateCommit(): void
    {
        $connection = $this->connections[0];
        $cursor = null;

        try {
            $connection->transaction(
                function (Connection $database) use (&$cursor): void {
                    $database->table('records')->insert(['label' => 'cursor']);
                    $cursor = $database->table('records')->iterateAssociative();
                },
                TransactionMode::Immediate,
            );
            self::fail('An immediate transaction with a live cursor unexpectedly committed.');
        } catch (TransactionStateException $exception) {
            self::assertTrue($exception->connectionUnusable);
        }

        self::assertInstanceOf(Cursor::class, $cursor);
        self::assertFalse($cursor->isClosed());
        $cursor->close();
    }

    private function connect(int $busyTimeoutMilliseconds = 5000): Connection
    {
        $connection = Connection::connect(
            Driver::Sqlite,
            'sqlite:' . $this->databasePath,
            connectionOptions: new ConnectionOptions(
                sqliteBusyTimeoutMilliseconds: $busyTimeoutMilliseconds,
                label: 'immediate-' . count($this->connections),
            ),
        );
        $this->connections[] = $connection;

        return $connection;
    }
}
