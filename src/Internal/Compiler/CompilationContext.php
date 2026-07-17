<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\Binding;

/** @internal */
final class CompilationContext
{
    /** @var list<Binding> */
    private array $bindings = [];

    public function bind(Binding $binding): void
    {
        $this->bindings[] = $binding->concrete();
    }

    /** @param list<Binding> $bindings */
    public function bindAll(array $bindings): void
    {
        foreach ($bindings as $binding) {
            $this->bind($binding);
        }
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
