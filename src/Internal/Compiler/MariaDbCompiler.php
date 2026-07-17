<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Internal\Ast\QueryState;

/** @internal */
final class MariaDbCompiler extends AbstractDialectCompiler
{
    #[\Override]
    protected function quoteCharacter(): string
    {
        return '`';
    }

    #[\Override]
    protected function lock(QueryState $state): string
    {
        $this->validateLockShape($state);
        if ($state->lock->mode === null) {
            return '';
        }

        $sql = $state->lock->mode === 'update' ? ' FOR UPDATE' : ' LOCK IN SHARE MODE';

        return $sql . ($state->lock->modifier === null ? '' : ' ' . $state->lock->modifier);
    }
}
