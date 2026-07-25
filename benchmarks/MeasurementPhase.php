<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Benchmark;

enum MeasurementPhase: string
{
    case Parity = 'Correctness parity';
    case WarmUp = 'Warm-up correctness';
    case Timed = 'Timed correctness';
}
