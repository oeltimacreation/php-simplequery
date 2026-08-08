<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class MySqlCompilerTest extends TestCase
{
    public function testMySqlUsesForShareLockSyntax(): void
    {
        $db = CompilerConnection::for(Driver::MySql);

        self::assertSame('SELECT * FROM `jobs` FOR SHARE', $db->table('jobs')->forShare()->compile()->sql);
        self::assertSame(
            'SELECT * FROM `jobs` FOR UPDATE NOWAIT',
            $db->table('jobs')->forUpdate()->noWait()->compile()->sql,
        );
    }
}
