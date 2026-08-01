<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Internal\Ast\QueryState;

/**
 * Shared MySQL/MariaDB compilation. The family differs only in the shared-lock
 * clause each dialect emits; everything else is inherited from the base.
 *
 * @internal
 */
abstract class MySqlFamilyCompiler extends AbstractDialectCompiler
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

        $sql = $state->lock->mode === 'update' ? ' FOR UPDATE' : ' ' . $this->sharedLockClause();

        return $sql . ($state->lock->modifier === null ? '' : ' ' . $state->lock->modifier);
    }

    abstract protected function sharedLockClause(): string;
}
