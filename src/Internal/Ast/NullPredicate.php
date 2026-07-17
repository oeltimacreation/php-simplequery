<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class NullPredicate implements Predicate
{
    public function __construct(
        public Identifier $column,
        public bool $negated,
    ) {
    }
}
