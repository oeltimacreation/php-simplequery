<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Expression;

use Oeltima\SimpleQuery\Internal\InputNormalizer;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;

final readonly class RawExpression
{
    /**
     * @var list<\Oeltima\SimpleQuery\Binding>
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

        $this->bindings = InputNormalizer::rawBindings($bindings, 'Raw bindings must be an ordered list.');
    }
}
