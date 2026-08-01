<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Compiler;

use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Testing\CompilerConnection;
use PHPUnit\Framework\TestCase;

final class SqliteCompilerTest extends TestCase
{
    public function testSqliteRejectsTypedRowLocks(): void
    {
        $db = CompilerConnection::for(Driver::Sqlite);

        $this->expectException(UnsupportedFeatureException::class);
        $db->table('users')->forUpdate()->compile();
    }
}
