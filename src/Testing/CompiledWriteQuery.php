<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Testing;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Internal\Compiler\CompilerFactory;
use Oeltima\SimpleQuery\Internal\Compiler\DialectCompiler;
use Oeltima\SimpleQuery\QueryBuilder;

final class CompiledWriteQuery
{
    /** @param array<string, mixed> $row */
    public static function insert(QueryBuilder $builder, array $row): CompiledQuery
    {
        return self::compiler($builder)->insert($builder->snapshotForCompilation(), $row);
    }

    /** @param array<array-key, array<string, mixed>> $rows */
    public static function insertMany(QueryBuilder $builder, array $rows): CompiledQuery
    {
        return self::compiler($builder)->insertMany($builder->snapshotForCompilation(), $rows);
    }

    /** @param array<string, mixed> $changes */
    public static function update(QueryBuilder $builder, array $changes): CompiledQuery
    {
        return self::compiler($builder)->update($builder->snapshotForCompilation(), $changes);
    }

    public static function delete(QueryBuilder $builder): CompiledQuery
    {
        return self::compiler($builder)->delete($builder->snapshotForCompilation());
    }

    private static function compiler(QueryBuilder $builder): DialectCompiler
    {
        return CompilerFactory::for($builder->driverForCompilation());
    }
}
