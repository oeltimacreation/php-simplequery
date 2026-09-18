<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Fixtures\LazyInjection;

use Oeltima\SimpleQuery\Connection;

/**
 * Synthetic model that resolves the lazy owner on every call.
 *
 * Two instances share one owner when they are given the same LazyDatabase,
 * which is the behavior a transaction needs. It is a test fixture, not a
 * library API.
 */
final class LazyModel
{
    public function __construct(
        private readonly LazyDatabase $database,
        private readonly string $table,
    ) {
    }

    public function create(string $name): string
    {
        return $this->database->connection()->table($this->table)->insertGetId(['name' => $name]);
    }

    public function rename(int $id, string $name): int
    {
        return $this->database->connection()->table($this->table)->where('id', $id)->update(['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->database->connection()->table($this->table)->where('id', $id)->firstAssociative();
    }

    /** @return array<string, mixed>|null */
    public function lockRow(int $id, bool $noWait = false): ?array
    {
        $query = $this->database->connection()->table($this->table)->where('id', $id)->forUpdate();
        if ($noWait) {
            $query = $query->noWait();
        }

        return $query->firstAssociative();
    }

    public function owner(): Connection
    {
        return $this->database->connection();
    }

    public function renderFromCache(): string
    {
        return $this->table . ':cached';
    }
}
