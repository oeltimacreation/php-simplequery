<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Exception\UnsupportedFeatureException;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;

/** @internal */
final class SqliteCompiler extends AbstractDialectCompiler
{
    #[\Override]
    protected function quoteCharacter(): string
    {
        return '"';
    }

    #[\Override]
    protected function lock(QueryState $state): string
    {
        if ($state->lock->mode !== null) {
            throw new UnsupportedFeatureException('SQLite does not support row-lock clauses.');
        }

        return '';
    }
}
