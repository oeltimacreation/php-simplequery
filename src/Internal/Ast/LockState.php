<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
final class LockState
{
    public ?LockMode $mode = null;
    public ?LockModifier $modifier = null;

    public function copy(): self
    {
        $copy = new self();
        $copy->mode = $this->mode;
        $copy->modifier = $this->modifier;

        return $copy;
    }
}
