<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

/** @internal */
final class MariaDbCompiler extends MySqlFamilyCompiler
{
    #[\Override]
    protected function sharedLockClause(): string
    {
        return 'LOCK IN SHARE MODE';
    }
}
