<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Consumer;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\SortDirection;
use Oeltima\SimpleQuery\Testing\CompiledQueryAssertions;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CleanConsumerLifecycleTest extends TestCase
{
    public function testCompleteConsumerLifecycle(): void
    {
        // 1. Connection setup
        $db = Connection::connect(
            Driver::Sqlite,
            'sqlite::memory:',
            connectionOptions: new ConnectionOptions(label: 'test-consumer'),
        );

        // 2. Detached compiler testing without execution
        $compiler = CompilerConnection::for(Driver::Sqlite);
        $compiled = $compiler
            ->table('tasks')
            ->where('active', true)
            ->orderBy('id', SortDirection::Desc)
            ->forPage(1, 10)
            ->compile();

        CompiledQueryAssertions::assertMatches(
            $compiled,
            'SELECT * FROM "tasks" WHERE "active" = ? ORDER BY "id" DESC LIMIT 10 OFFSET 0',
            [1],
        );

        // 3. Table creation via raw DDL
        $db->query(
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                score INTEGER NOT NULL,
                completed INTEGER NOT NULL
            )',
        )->execute();

        // 4. Single writes & ID generation
        $id1 = $db->table('tasks')->insertGetId([
            'title' => 'First task',
            'score' => 10,
            'completed' => 0,
        ]);
        self::assertSame('1', $id1);

        $affected = $db->table('tasks')->insert([
            'title' => 'Second task',
            'score' => 20,
            'completed' => 0,
        ]);
        self::assertSame(1, $affected);

        // 5. Batch writes
        $batchAffected = $db->table('tasks')->insertMany([
            ['title' => 'Batch 1', 'score' => 30, 'completed' => 1],
            ['title' => 'Batch 2', 'score' => 40, 'completed' => 1],
            ['title' => 'Batch 3', 'score' => 50, 'completed' => 1],
        ]);
        self::assertSame(3, $batchAffected);

        // 6. Reads: Objects, Associative, Aggregates
        $allRows = $db->table('tasks')->orderBy('id')->get();
        self::assertCount(5, $allRows);
        self::assertSame('First task', $allRows[0]->title);

        $associativeRows = $db->table('tasks')->orderBy('id')->getAssociative();
        self::assertCount(5, $associativeRows);
        self::assertSame('First task', $associativeRows[0]['title']);

        $firstRow = $db->table('tasks')->where('id', 2)->first();
        self::assertNotNull($firstRow);
        self::assertSame('Second task', $firstRow->title);

        $firstAssoc = $db->table('tasks')->where('id', 2)->firstAssociative();
        self::assertNotNull($firstAssoc);
        self::assertSame('Second task', $firstAssoc['title']);

        $missing = $db->table('tasks')->where('id', 999)->firstAssociative();
        self::assertNull($missing);

        // Aggregates
        self::assertSame(5, $db->table('tasks')->count());
        self::assertSame(3, $db->table('tasks')->where('completed', 1)->count());

        $sum = $db->table('tasks')->sum('score');
        self::assertTrue(is_numeric($sum));
        self::assertSame(150, (int) $sum);

        $average = $db->table('tasks')->average('score');
        self::assertTrue(is_numeric($average));
        self::assertSame(30, (int) $average);

        $min = $db->table('tasks')->min('score');
        self::assertTrue(is_numeric($min));
        self::assertSame(10, (int) $min);

        $max = $db->table('tasks')->max('score');
        self::assertTrue(is_numeric($max));
        self::assertSame(50, (int) $max);

        // 7. Streaming & Cursor cleanup
        $cursor = $db->table('tasks')->orderBy('id')->iterateAssociative();
        $streamed = [];
        try {
            foreach ($cursor as $row) {
                $streamed[] = $row['title'];
                if (count($streamed) === 2) {
                    break;
                }
            }
        } finally {
            $cursor->close();
        }
        self::assertSame(['First task', 'Second task'], $streamed);

        // 8. Updates & Deletes
        $updated = $db->table('tasks')->where('id', 1)->update(['score' => 99]);
        self::assertSame(1, $updated);

        $deleted = $db->table('tasks')->where('id', 1)->delete();
        self::assertSame(1, $deleted);
        self::assertSame(4, $db->table('tasks')->count());

        // 9. Managed transactions & savepoints
        $txResult = $db->transaction(function (Connection $connection): string {
            $newId = $connection->table('tasks')->insertGetId([
                'title' => 'Tx item',
                'score' => 77,
                'completed' => 0,
            ]);

            // Nested savepoint
            $connection->transaction(function (Connection $nested) use ($newId): void {
                $nested->table('tasks')->where('id', (int) $newId)->update(['score' => 88]);
            });

            return $newId;
        });
        self::assertSame('6', $txResult);
        $txRow = $db->table('tasks')->where('id', (int) $txResult)->firstAssociative();
        self::assertNotNull($txRow);
        $txScore = $txRow['score'];
        self::assertTrue(is_numeric($txScore));
        self::assertSame(88, (int) $txScore);

        // Rollback test
        try {
            $db->transaction(function (Connection $connection): void {
                $connection->table('tasks')->insert([
                    'title' => 'To be rolled back',
                    'score' => 0,
                    'completed' => 0,
                ]);
                throw new RuntimeException('Intentional domain rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Intentional domain rollback', $exception->getMessage());
        }
        self::assertNull($db->table('tasks')->where('title', 'To be rolled back')->firstAssociative());

        // 10. Failure diagnostics without credential/binding leaks
        try {
            $db->table('tasks')->where('invalid_col', '>', null);
            self::fail('Expected InvalidQueryException');
        } catch (InvalidQueryException $exception) {
            self::assertStringContainsString('null', $exception->getMessage());
        }

        try {
            $db->table('non_existent_table')->where('secret_col', 'secret_value_12345')->get();
            self::fail('Expected QueryExecutionException');
        } catch (QueryExecutionException $exception) {
            self::assertSame(Driver::Sqlite, $exception->driver);
            self::assertSame('test-consumer', $exception->connectionLabel);
            self::assertStringNotContainsString('secret_value_12345', $exception->getMessage());
            self::assertNotNull($exception->sqlState);
        }

        // 11. Connection close
        $db->close();

        try {
            $db->table('tasks')->get();
            self::fail('Expected ConnectionException on closed connection');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('closed', $exception->getMessage());
        }
    }
}
