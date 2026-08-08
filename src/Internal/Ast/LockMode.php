<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
enum LockMode: string
{
    case Update = 'update';
    case Share = 'share';
}
