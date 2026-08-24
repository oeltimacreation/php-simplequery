<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

use RuntimeException;

final readonly class ComparisonThresholds
{
    public function __construct(
        public float $thresholdPercent = 5.0,
        public float $absoluteNoiseFloorMs = 0.0,
        public float $subMillisecondCeilingMs = 1.0,
    ) {
        if (
            !is_finite($thresholdPercent)
            || !is_finite($absoluteNoiseFloorMs)
            || !is_finite($subMillisecondCeilingMs)
            || $thresholdPercent < 0.0
            || $absoluteNoiseFloorMs < 0.0
            || $subMillisecondCeilingMs <= 0.0
        ) {
            throw new RuntimeException('Benchmark comparison thresholds must be non-negative and finite.');
        }
    }
}
