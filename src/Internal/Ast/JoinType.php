<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

/** @internal */
enum JoinType: string
{
    case Inner = 'INNER';
    case Left = 'LEFT';
}
