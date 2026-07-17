<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDOException;
use PDOStatement;

final class ThrowingCloseStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    #[\Override]
    public function closeCursor(): bool
    {
        throw new PDOException('Controlled cursor-close failure.');
    }
}
