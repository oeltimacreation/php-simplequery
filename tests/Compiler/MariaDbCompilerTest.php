<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class MariaDbCompilerTest extends TestCase
{
    public function testMariaDbUsesItsSharedLockSyntax(): void
    {
        $db = CompilerConnection::for(Driver::MariaDb);

        self::assertSame(
            'SELECT * FROM `jobs` WHERE `ready` = ? LOCK IN SHARE MODE NOWAIT',
            $db->table('jobs')->where('ready', true)->forShare()->noWait()->compile()->sql,
        );
        self::assertSame(
            'SELECT * FROM `jobs` FOR UPDATE SKIP LOCKED',
            $db->table('jobs')->forUpdate()->skipLocked()->compile()->sql,
        );
    }
}
