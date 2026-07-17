<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOStatement;

final class ControlledFetchStatement extends PDOStatement
{
    public static mixed $row = null;

    protected function __construct()
    {
    }

    #[\Override]
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return self::$row;
    }
}
