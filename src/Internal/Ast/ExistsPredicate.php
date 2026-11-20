<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final readonly class ExistsPredicate implements Predicate
{
    public function __construct(
        public SubquerySnapshot $subquery,
        public bool $negated,
    ) {
    }
}
