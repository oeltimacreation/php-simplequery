<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final readonly class JoinState
{
    public function __construct(
        public JoinType $type,
        public Source $source,
        public ConditionCollection $conditions,
    ) {
    }

    public function copy(): self
    {
        return new self($this->type, $this->source, $this->conditions->copy());
    }
}
