<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Internal\Executor;
use stdClass;

final readonly class RawQuery
{
    private CompiledQuery $query;

    /**
     * @internal
     * @param list<mixed> $bindings
     */
    public function __construct(private Connection $connection, string $trustedSql, array $bindings = [])
    {
        $this->query = new CompiledQuery($trustedSql, self::normalizedBindings($bindings));
    }

    /** @return list<stdClass> */
    public function get(): array
    {
        return $this->executor()->getObjects($this->query);
    }

    public function first(): ?stdClass
    {
        return $this->executor()->firstObject($this->query);
    }

    /** @return list<array<string, mixed>> */
    public function getAssociative(): array
    {
        return $this->executor()->getAssociative($this->query);
    }

    /** @return array<string, mixed>|null */
    public function firstAssociative(): ?array
    {
        return $this->executor()->firstAssociative($this->query);
    }

    /** @return Cursor<stdClass> */
    public function iterate(): Cursor
    {
        return $this->executor()->objectCursor($this->query);
    }

    /** @return Cursor<array<string, mixed>> */
    public function iterateAssociative(): Cursor
    {
        return $this->executor()->associativeCursor($this->query);
    }

    public function execute(): int
    {
        return $this->executor()->affectedRows($this->query);
    }

    private function executor(): Executor
    {
        return new Executor($this->connection);
    }

    /**
     * @param array<mixed> $bindings
     * @return list<Binding>
     */
    private static function normalizedBindings(array $bindings): array
    {
        if (!array_is_list($bindings)) {
            throw new Exception\InvalidQueryException('Raw query bindings must be an ordered list.');
        }

        return array_map(Binding::fromValue(...), $bindings);
    }
}
