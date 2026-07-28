<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;

/** @internal */
final readonly class ExpressionIdentifierComparisonPredicate implements Predicate
{
    public function __construct(
        public Identifier|RawExpression $left,
        public ComparisonOperator $operator,
        public Identifier|RawExpression $right,
    ) {
    }
}
