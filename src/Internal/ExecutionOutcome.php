<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

/** @internal */
final readonly class ExecutionOutcome
{
    public function __construct(
        public bool $successful,
        public ?int $affectedRows,
    ) {
    }
}
