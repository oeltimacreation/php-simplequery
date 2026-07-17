<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final readonly class GroupPredicate implements Predicate
{
    public function __construct(public ConditionCollection $conditions)
    {
    }
}
