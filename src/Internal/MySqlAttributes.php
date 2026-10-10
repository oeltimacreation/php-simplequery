<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use PDO;
use Pdo\Mysql;

/** @internal PDO driver constants moved to Pdo\Mysql in PHP 8.4. */
final class MySqlAttributes
{
    public static function bufferedQuery(): int
    {
        return class_exists(Mysql::class) ? Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    public static function foundRows(): int
    {
        return class_exists(Mysql::class) ? Mysql::ATTR_FOUND_ROWS : PDO::MYSQL_ATTR_FOUND_ROWS;
    }
}
