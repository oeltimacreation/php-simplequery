<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final readonly class ConditionTerm
{
    public function __construct(
        public Predicate $predicate,
        public bool $or,
        public bool $negated = false,
    ) {
    }
}
