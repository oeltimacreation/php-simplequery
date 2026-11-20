<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class InPredicate implements Predicate
{
    /**
     * @param list<\Oeltima\SimpleQuery\Binding>|SubquerySnapshot $values
     */
    public function __construct(
        public Identifier $column,
        public array|SubquerySnapshot $values,
        public bool $negated,
    ) {
    }
}
