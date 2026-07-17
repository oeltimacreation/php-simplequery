<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

enum Driver: string
{
    case MariaDb = 'mariadb';
    case MySql = 'mysql';
    case Sqlite = 'sqlite';

    public function pdoDriver(): string
    {
        return match ($this) {
            self::MariaDb, self::MySql => 'mysql',
            self::Sqlite => 'sqlite',
        };
    }
}
