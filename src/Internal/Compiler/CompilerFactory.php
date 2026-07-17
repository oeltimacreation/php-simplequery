<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Driver;

/** @internal */
final class CompilerFactory
{
    public static function for(Driver $driver): DialectCompiler
    {
        return match ($driver) {
            Driver::MariaDb => new MariaDbCompiler(),
            Driver::MySql => new MySqlCompiler(),
            Driver::Sqlite => new SqliteCompiler(),
        };
    }
}
