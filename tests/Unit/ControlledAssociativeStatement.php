<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;

final class ControlledAssociativeStatement extends PDOStatement
{
    public static mixed $row = false;

    public static bool $throwOnFetch = false;

    public static bool $closed = false;

    protected function __construct()
    {
    }

    #[\Override]
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        if (self::$throwOnFetch) {
            throw new PDOException('Controlled associative hydration fetch failure.');
        }

        $row = self::$row;
        self::$row = false;

        return $row;
    }

    #[\Override]
    public function closeCursor(): bool
    {
        self::$closed = true;

        return parent::closeCursor();
    }
}
