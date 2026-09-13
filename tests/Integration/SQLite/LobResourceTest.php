<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Integration\SQLite;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\ParameterType;
use Oeltima\SimpleQuery\Tools\DatabaseProbe\LobResourceProbe;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class LobResourceTest extends TestCase
{
    public function testSqliteResourceConsumption(): void
    {
        $db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $report = (new LobResourceProbe())->run($db);
        $expected = ['current_position' => 'cdef', 'repeated' => '', 'caller_rewind' => 'abcdef', 'empty' => ''];
        foreach ($expected as $key => $value) {
            self::assertIsArray($report[$key]);
            self::assertSame(hash('sha256', $value), $report[$key]['sha256']);
        }
        self::assertSame(6, $report['position_after']);
        self::assertTrue($report['caller_still_owns_stream']);
        self::assertSame(InvalidQueryException::class, $report['closed_before_binding']);
        $db->close();
    }

    public function testSnapshotsAndWritesShareCallerStream(): void
    {
        $db = Connection::connect(Driver::Sqlite, 'sqlite::memory:');
        $db->query('CREATE TABLE items (payload BLOB)')->execute();
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            fwrite($stream, 'abcdef');
            fseek($stream, 2);
            $binding = new Binding($stream, ParameterType::Lob);
            $child = $db->table('items')->select($db->raw('? AS payload', [$binding]));
            $parent = $db->table($child, 'nested');
            $copy = clone $parent;
            self::assertSame($stream, $copy->compile()->bindings[0]->value);
            self::assertSame(2, ftell($stream));
            $db->table('items')->insert(['payload' => $binding]);
            self::assertSame('cdef', $db->table('items')->firstAssociative()['payload'] ?? null);
            self::assertSame('', $copy->firstAssociative()['payload'] ?? null);
            rewind($stream);
            self::assertSame('abcdef', $parent->firstAssociative()['payload'] ?? null);
            self::assertIsResource($stream);
        } finally {
            fclose($stream);
            $db->close();
        }
    }
}
