<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

final readonly class TransitionExpectation
{
    /** @param class-string<\Throwable>|null $exception */
    public function __construct(
        public ?string $exception = null,
        public ?string $operation = null,
        public ?bool $connectionUnusable = null,
    ) {
    }
}
