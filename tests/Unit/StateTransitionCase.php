<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Unit;

use Closure;

final readonly class StateTransitionCase
{
    /**
     * @param Closure(): array{\Oeltima\SimpleQuery\Connection, mixed, Closure(): void} $fixtureFactory
     * @param Closure(\Oeltima\SimpleQuery\Connection): mixed $operation
     */
    public function __construct(
        public string $stateLabel,
        public string $operationLabel,
        public Closure $fixtureFactory,
        public Closure $operation,
        public TransitionExpectation $expectation = new TransitionExpectation(),
    ) {
    }
}
