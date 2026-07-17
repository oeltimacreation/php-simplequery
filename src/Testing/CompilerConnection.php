<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Testing;

use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Driver;

final class CompilerConnection
{
    public static function for(Driver $driver): Connection
    {
        return Connection::forCompilation($driver);
    }
}
