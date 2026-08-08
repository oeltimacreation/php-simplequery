<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

/** @internal */
final readonly class RequestedBooleanOption
{
    public function __construct(
        public int $attribute,
        public ?bool $declared,
        public bool $default,
        public string $name,
    ) {
    }
}
