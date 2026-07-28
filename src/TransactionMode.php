<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

enum TransactionMode: string
{
    case Default = 'default';
    case Immediate = 'immediate';
}
