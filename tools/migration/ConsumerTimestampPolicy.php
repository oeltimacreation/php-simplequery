<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

enum ConsumerTimestampPolicy
{
    case Current;
    case Deterministic;
}
