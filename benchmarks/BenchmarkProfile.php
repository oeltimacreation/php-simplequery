<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

enum BenchmarkProfile: string
{
    case Ci = 'ci';
    case Reference = 'reference';

    /** @param array{ci: int, reference: int} $sizes */
    public function select(array $sizes): int
    {
        return $sizes[$this->value];
    }
}
