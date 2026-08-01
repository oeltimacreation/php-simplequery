<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final class JoinState
{
    public function __construct(
        public readonly JoinType $type,
        public readonly Source $source,
        public ConditionCollection $conditions,
    ) {
    }

    public function copy(): self
    {
        return new self($this->type, $this->source, $this->conditions->copy());
    }
}
