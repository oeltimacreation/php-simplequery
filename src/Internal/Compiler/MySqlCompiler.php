<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

/** @internal */
final class MySqlCompiler extends MySqlFamilyCompiler
{
    #[\Override]
    protected function sharedLockClause(): string
    {
        return 'FOR SHARE';
    }
}
