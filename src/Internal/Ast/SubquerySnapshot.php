<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\CompiledQuery;

/** @internal */
final readonly class SubquerySnapshot
{
    public function __construct(
        public CompiledQuery $query,
        public bool $containsStructuredExists,
    ) {
    }
}
