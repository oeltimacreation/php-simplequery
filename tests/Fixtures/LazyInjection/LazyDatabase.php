<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tests\Fixtures\LazyInjection;

use Closure;
use Oeltima\SimpleQuery\Connection;

/**
 * Synthetic application-owned lazy database handle.
 *
 * It opens no connection until the first query path asks for one and counts
 * factory invocations so cache-only paths can be asserted. It is a test
 * fixture, not a library API.
 */
final class LazyDatabase
{
    private ?Connection $connection = null;

    private int $factoryCalls = 0;

    /** @param Closure(): Connection $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            ++$this->factoryCalls;
            $this->connection = ($this->factory)();
        }

        return $this->connection;
    }

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->connection()->transaction($callback);
    }

    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        if ($connection !== null) {
            $connection->close();
        }
    }

    public function factoryCalls(): int
    {
        return $this->factoryCalls;
    }

    public function opened(): bool
    {
        return $this->connection !== null;
    }
}
