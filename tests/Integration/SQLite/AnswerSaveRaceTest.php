<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Synthetic answer-save and completion races.
 *
 * Unique keys, authorization, locks, and ordering stay application-owned; these
 * tests characterize the reconciliation patterns a consumer needs and show that
 * an upsert alone is not end-to-end idempotency.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class AnswerSaveRaceTest extends TestCase
{
    private string $path = '';

    private Connection $first;

    private Connection $second;

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'simplequery_answers_');
        if (!is_string($path)) {
            self::fail('Could not create the synthetic answer-save database.');
        }
        $this->path = $path;
        $this->first = Connection::connect(Driver::Sqlite, 'sqlite:' . $this->path);
        $this->second = Connection::connect(Driver::Sqlite, 'sqlite:' . $this->path);
        $this->first->pdo()->exec(
            'CREATE TABLE answers ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'submission_key TEXT NOT NULL UNIQUE, '
            . 'state TEXT NOT NULL, '
            . 'attempts INTEGER NOT NULL DEFAULT 1)',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $connection) {
            try {
                $connection->close();
            } catch (\Throwable) {
                // Diagnostics keep the connection on a failing assertion.
            }
        }
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testDuplicateSubmissionLosesToTheUniqueKeyAndIsReconciled(): void
    {
        $this->first->table('answers')->insert([
            'submission_key' => 'attempt-1-question-1',
            'state' => 'saved',
        ]);

        try {
            $this->second->table('answers')->insert([
                'submission_key' => 'attempt-1-question-1',
                'state' => 'saved',
            ]);
            self::fail('A duplicate submission unexpectedly inserted a second row.');
        } catch (QueryExecutionException $failure) {
            self::assertSame('23000', $failure->sqlState);
        }

        $row = $this->second
            ->table('answers')
            ->where('submission_key', 'attempt-1-question-1')
            ->firstAssociative();
        self::assertSame('saved', $row['state'] ?? null);
        self::assertSame(1, $this->second->table('answers')->count());
    }

    public function testCompletionUpdateIsIdempotentOnlyWithAStateGuard(): void
    {
        $this->first->table('answers')->insert([
            'submission_key' => 'attempt-2-question-1',
            'state' => 'ready',
        ]);

        $completed = $this->second
            ->table('answers')
            ->where('submission_key', 'attempt-2-question-1')
            ->where('state', 'ready')
            ->update(['state' => 'completed']);
        $repeated = $this->second
            ->table('answers')
            ->where('submission_key', 'attempt-2-question-1')
            ->where('state', 'ready')
            ->update(['state' => 'completed']);

        self::assertSame(1, $completed);
        self::assertSame(0, $repeated);
        self::assertSame(
            'completed',
            $this->first
                ->table('answers')
                ->where('submission_key', 'attempt-2-question-1')
                ->firstAssociative()['state'] ?? null,
        );
    }

    public function testUpsertAloneIsNotIdempotent(): void
    {
        $upsert = 'INSERT INTO answers (submission_key, state, attempts) VALUES (?, ?, 1) '
            . 'ON CONFLICT(submission_key) DO UPDATE SET attempts = attempts + 1';

        $this->first->query($upsert, ['attempt-3-question-1', 'draft'])->execute();
        $this->second->query($upsert, ['attempt-3-question-1', 'draft'])->execute();

        $row = $this->first
            ->table('answers')
            ->where('submission_key', 'attempt-3-question-1')
            ->firstAssociative();
        self::assertSame(2, $row['attempts'] ?? null);
        self::assertSame(1, $this->first->table('answers')->where(
            'submission_key',
            'attempt-3-question-1',
        )->count());
    }

    public function testFailureAfterDispatchReconcilesWithoutReplay(): void
    {
        $dispatched = false;
        $dispatch = function () use (&$dispatched): void {
            $this->first->table('answers')->insert([
                'submission_key' => 'attempt-4-question-1',
                'state' => 'saved',
            ]);
            $dispatched = true;

            throw new RuntimeException('Synthetic connection loss after dispatch.');
        };

        try {
            $dispatch();
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        self::assertTrue($dispatched);

        // Reconciliation reads the idempotency key; it never replays the write.
        $row = $this->second
            ->table('answers')
            ->where('submission_key', 'attempt-4-question-1')
            ->firstAssociative();
        self::assertSame('saved', $row['state'] ?? null);
        self::assertSame(
            1,
            $this->second->table('answers')->where('submission_key', 'attempt-4-question-1')->count(),
        );
        self::assertSame(1, $this->second->table('answers')->count());
    }
}
