<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
enum LockModifier: string
{
    case NoWait = 'NOWAIT';
    case SkipLocked = 'SKIP LOCKED';
}
