<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

final readonly class RewriteDecision
{
    public function __construct(
        public bool $safe,
        public string $reason,
        public ?string $rewrittenSource = null,
    ) {
    }
}
