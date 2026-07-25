<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use PDOStatement;

final class FalseCloseStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    #[\Override]
    public function closeCursor(): bool
    {
        return false;
    }
}
