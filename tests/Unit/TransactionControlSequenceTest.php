<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ExternalTransactionException;
use Oeltima\SimpleQuery\Exception\TransactionException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks the exact transaction-control sequence and cost shape.
 *
 * The root ownership guard is a savepoint pair; nested scopes add one pair and
 * a failure adds a rollback-to-savepoint. These assertions keep a future
 * optimization from silently dropping an ownership or state check.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class TransactionControlSequenceTest extends TestCase
{
    public function testRootSuccessIssuesExactlyBeginGuardReleaseCommit(): void
    {
        [$pdo, $connection] = $this->connection();
        $result = null;
        $connection->transaction(static function () use (&$result): void {
            $result = 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(
            [
                'begin',
                'SAVEPOINT simplequery_root_1',
                'RELEASE SAVEPOINT simplequery_root_1',
                'commit',
            ],
            $pdo->controlCalls,
        );
        self::assertSame(4, $pdo->inspectionCalls);
        $connection->close();
    }

    public function testNestedSuccessAddsExactlyOneSavepointPair(): void
    {
        [$pdo, $connection] = $this->connection();

        $connection->transaction(static function (Connection $database): void {
            $database->transaction(static fn (): int => 1);
            $database->transaction(static fn (): int => 2);
        });

        self::assertSame(
            [
                'begin',
                'SAVEPOINT simplequery_root_1',
                'SAVEPOINT simplequery_nested_2',
                'RELEASE SAVEPOINT simplequery_nested_2',
                'SAVEPOINT simplequery_nested_3',
                'RELEASE SAVEPOINT simplequery_nested_3',
                'RELEASE SAVEPOINT simplequery_root_1',
                'commit',
            ],
            $pdo->controlCalls,
        );
        self::assertSame(8, $pdo->inspectionCalls);
        $connection->close();
    }

    public function testNestedFailureRollsBackToSavepointWhileOuterContinues(): void
    {
        [$pdo, $connection] = $this->connection();
        $innerFailure = new RuntimeException('Synthetic inner failure.');
        $outerContinued = false;

        $connection->transaction(function (Connection $database) use ($innerFailure, &$outerContinued): void {
            try {
                $database->transaction(static function () use ($innerFailure): void {
                    throw $innerFailure;
                });
            } catch (RuntimeException $failure) {
                self::assertSame($innerFailure, $failure);
            }

            $outerContinued = true;
        });

        self::assertTrue($outerContinued);
        self::assertSame(
            [
                'begin',
                'SAVEPOINT simplequery_root_1',
                'SAVEPOINT simplequery_nested_2',
                'ROLLBACK TO SAVEPOINT simplequery_nested_2',
                'RELEASE SAVEPOINT simplequery_nested_2',
                'RELEASE SAVEPOINT simplequery_root_1',
                'commit',
            ],
            $pdo->controlCalls,
        );
        $connection->close();
    }

    public function testOuterFailureReleasesTheGuardBeforeRollback(): void
    {
        [$pdo, $connection] = $this->connection();
        $failure = new RuntimeException('Synthetic outer failure.');

        try {
            $connection->transaction(static function () use ($failure): void {
                throw $failure;
            });
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(
            [
                'begin',
                'SAVEPOINT simplequery_root_1',
                'RELEASE SAVEPOINT simplequery_root_1',
                'rollback',
            ],
            $pdo->controlCalls,
        );
        $connection->close();
    }

    public function testExternalTransactionRejectionIssuesNoManagedControlSql(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->beginTransaction();
        $before = $pdo->controlCalls;

        try {
            $connection->transaction(static fn (): string => 'not-adopted');
            self::fail('The external transaction was unexpectedly adopted.');
        } catch (ExternalTransactionException) {
            self::assertSame(['begin'], $before);
            self::assertSame($before, $pdo->controlCalls);
        }
        $pdo->rollBack();
        $connection->close();
    }

    public function testFailedBeginIssuesNoGuardOrCompletionSql(): void
    {
        [$pdo, $connection] = $this->connection();
        $pdo->failBegin = true;

        try {
            $connection->transaction(static fn (): string => 'not-reached');
            self::fail('The controlled begin failure was not reported.');
        } catch (TransactionException) {
            self::assertSame(['begin'], $pdo->controlCalls);
        }
        $connection->close();
    }

    /** @return array{ControlledTransactionPdo, Connection} */
    private function connection(): array
    {
        $pdo = new ControlledTransactionPdo();

        return [$pdo, Connection::fromPdo($pdo, Driver::Sqlite)];
    }
}
