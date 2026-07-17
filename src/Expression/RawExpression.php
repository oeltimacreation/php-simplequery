<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Expression;

use Oeltima\SimpleQuery\Binding;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class RawExpression
{
    /**
     * @var list<Binding>
     */
    public array $bindings;

    /**
     * @param array<array-key, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        array $bindings = [],
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidQueryException('Trusted raw SQL cannot be empty.');
        }

        if (!array_is_list($bindings)) {
            throw new InvalidQueryException('Raw bindings must be an ordered list.');
        }

        $normalized = [];
        foreach ($bindings as $binding) {
            $normalized[] = Binding::fromValue($binding);
        }
        $this->bindings = $normalized;
    }
}
