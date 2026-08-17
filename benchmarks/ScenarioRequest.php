<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

final readonly class ScenarioRequest
{
    public function __construct(
        public ScenarioName $name,
        public string $profile,
    ) {
    }

    /** @param array{ci: int, reference: int} $sizes */
    public function scale(array $sizes): int
    {
        return $sizes[$this->profile];
    }
}
