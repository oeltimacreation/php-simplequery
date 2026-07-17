<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\SortDirection;

/** @internal */
final readonly class OrderClause
{
    public function __construct(
        public Identifier|RawExpression $expression,
        public SortDirection $direction,
    ) {
    }
}
