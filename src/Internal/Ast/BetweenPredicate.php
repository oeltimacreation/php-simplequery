<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class BetweenPredicate implements Predicate
{
    public function __construct(
        public Identifier $column,
        public Binding $from,
        public Binding $to,
    ) {
    }
}
