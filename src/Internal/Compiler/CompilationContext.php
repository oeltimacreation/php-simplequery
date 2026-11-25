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
        $this->bindings[] = $binding;
    }

    /** @param list<Binding> $bindings */
    public function bindAll(array $bindings): void
    {
        if ($this->bindings === []) {
            // PHP shares the first list until this compilation appends another binding.
            $this->bindings = $bindings;

            return;
        }

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
