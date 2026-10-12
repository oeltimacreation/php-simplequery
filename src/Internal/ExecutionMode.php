<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

/** @internal */
enum ExecutionMode
{
    case Result;
    case ObservedWrite;
    case Cursor;
}
