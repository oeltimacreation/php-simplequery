<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Closure;

final readonly class StateTransitionCase
{
    /**
     * @param Closure(): array{\Oeltima\SimpleQuery\Connection, mixed, Closure(): void} $fixtureFactory
     * @param Closure(\Oeltima\SimpleQuery\Connection): mixed $operation
     * @param class-string<\Throwable>|null $expectedException
     */
    public function __construct(
        public string $stateLabel,
        public string $operationLabel,
        public Closure $fixtureFactory,
        public Closure $operation,
        public ?string $expectedException = null,
        public ?string $expectedOperation = null,
        public ?bool $expectedUnusable = null,
    ) {
    }
}
