<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class CompiledQuery
{
    /** @var list<Binding> */
    public array $bindings;

    /**
     * @param array<array-key, Binding> $bindings
     */
    public function __construct(
        public string $sql,
        array $bindings = [],
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidQueryException('Compiled SQL cannot be empty.');
        }

        if (!array_is_list($bindings)) {
            throw new InvalidQueryException('Compiled bindings must be a list.');
        }

        $this->bindings = $bindings;
        foreach ($this->bindings as $binding) {
            if ($binding->type === ParameterType::Auto) {
                throw new InvalidQueryException('Compiled bindings must use concrete parameter types.');
            }
        }
    }
}
