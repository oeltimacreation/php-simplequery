<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal\Compiler;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Internal\Ast\QueryState;

/** @internal */
interface DialectCompiler
{
    public function select(QueryState $state): CompiledQuery;

    /** @param array<string, mixed> $row */
    public function insert(QueryState $state, array $row): CompiledQuery;

    /** @param array<array-key, array<string, mixed>> $rows */
    public function insertMany(QueryState $state, array $rows): CompiledQuery;

    /** @param array<string, mixed> $changes */
    public function update(QueryState $state, array $changes): CompiledQuery;

    public function delete(QueryState $state): CompiledQuery;
}
