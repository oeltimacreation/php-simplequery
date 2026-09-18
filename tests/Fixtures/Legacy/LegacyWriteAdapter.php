<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Fixtures\Legacy;

use Oeltima\SimpleQuery\Connection;

/**
 * Synthetic Pixie-era write adapter that preserves boolean write returns.
 *
 * Legacy adapters often returned booleans while the library returns affected
 * rows or generated-ID strings. This fixture, not a library API, characterizes
 * that mapping and its zero-row ambiguity.
 */
final class LegacyWriteAdapter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function insert(array $values): int
    {
        return $this->connection->table($this->table)->insert($values);
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values): string
    {
        return $this->connection->table($this->table)->insertGetId($values);
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertMany(array $rows): int
    {
        return $this->connection->table($this->table)->insertMany($rows);
    }

    /** @param array<string, mixed> $values */
    public function updateById(int $id, array $values): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->update($values) > 0;
    }

    public function deleteById(int $id): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->delete() > 0;
    }
}
