<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;

final class ThrowingFetchAndCloseStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    #[\Override]
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        throw new PDOException('Controlled cursor-fetch failure.');
    }

    #[\Override]
    public function closeCursor(): bool
    {
        throw new PDOException('Controlled cursor-close failure.');
    }
}
