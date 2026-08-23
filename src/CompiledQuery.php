<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class CompiledQuery
{
    /** @var list<Binding> */
    public array $bindings;

    /** @param list<Binding> $bindings */
    public function __construct(
        public string $sql,
        array $bindings = [],
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidQueryException('Compiled SQL cannot be empty.');
        }

        $this->bindings = self::validatedBindings($bindings);
        foreach ($this->bindings as $binding) {
            if ($binding->type === ParameterType::Auto) {
                throw new InvalidQueryException('Compiled bindings must use concrete parameter types.');
            }
        }
    }

    /**
     * @param array<mixed> $bindings
     * @return list<Binding>
     */
    private static function validatedBindings(array $bindings): array
    {
        if (!array_is_list($bindings)) {
            throw new InvalidQueryException('Compiled bindings must be a list.');
        }

        foreach ($bindings as $binding) {
            if (!$binding instanceof Binding) {
                throw new InvalidQueryException('Every compiled binding must be a Binding.');
            }
        }

        return $bindings;
    }
}
