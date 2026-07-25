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

    /** @param list<mixed> $bindings */
    public function __construct(
        public string $sql,
        array $bindings = [],
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidQueryException('Trusted raw SQL cannot be empty.');
        }

        $this->bindings = self::normalizedBindings($bindings);
    }

    /**
     * @param array<mixed> $bindings
     * @return list<Binding>
     */
    private static function normalizedBindings(array $bindings): array
    {
        if (!array_is_list($bindings)) {
            throw new InvalidQueryException('Raw bindings must be an ordered list.');
        }

        return array_map(Binding::fromValue(...), $bindings);
    }
}
