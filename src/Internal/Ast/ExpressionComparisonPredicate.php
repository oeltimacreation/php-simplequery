<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Expression\RawExpression;

/** @internal */
final readonly class ExpressionComparisonPredicate implements Predicate
{
    public function __construct(
        public RawExpression $expression,
        public ComparisonOperator $operator,
        public Binding $value,
    ) {
    }
}
