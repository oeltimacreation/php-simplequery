<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class InPredicate implements Predicate
{
    /**
     * @param list<\Oeltima\SimpleQuery\Binding>|CompiledQuery $values
     */
    public function __construct(
        public Identifier $column,
        public array|CompiledQuery $values,
        public bool $negated,
    ) {
    }
}
