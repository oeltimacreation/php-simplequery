<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\Migration;

enum ConsumerPathPolicy
{
    case Redacted;
    case Included;
}
