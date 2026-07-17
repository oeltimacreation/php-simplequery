<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Ast;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Expression\Identifier;

/** @internal */
final readonly class Source
{
    private function __construct(
        public ?Identifier $table,
        public ?CompiledQuery $subquery,
        public ?string $alias,
    ) {
    }

    public static function table(Identifier $table, ?string $alias = null): self
    {
        return new self($table, null, $alias);
    }

    public static function subquery(CompiledQuery $subquery, string $alias): self
    {
        return new self(null, $subquery, $alias);
    }

    public function isPhysicalTable(): bool
    {
        return $this->table !== null;
    }
}
