<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\RawExpression;

/** @internal */
final readonly class ExpressionNullPredicate implements Predicate
{
    public function __construct(
        public RawExpression $expression,
        public bool $negated,
    ) {
    }
}
