<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class IdentifierComparisonPredicate implements Predicate
{
    public function __construct(
        public Identifier $left,
        public ComparisonOperator $operator,
        public Identifier $right,
    ) {
    }
}
