<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Examples\WorkerLifecycle;

use Closure;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\QueryBuilder;
use RuntimeException;
use Throwable;

/**
 * One request unit. It owns every model, builder, and cursor created for the
 * request and releases them at the boundary; the holder keeps only the
 * connection for the next unit.
 */
final class RequestScope
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var array<string, bool> */
    private array $lost = [];

    /** @var list<Cursor<array<string, mixed>>> */
    private array $cursors = [];

    private bool $usedConnection = false;

    private bool $finished = false;

    /** @internal */
    public function __construct(private readonly WorkerDatabase $database)
    {
    }

    /** Lazily pins one connection per role for the whole unit. */
    public function connection(string $role = 'primary'): Connection
    {
        $this->assertOpen();
        if (isset($this->lost[$role])) {
            throw new RuntimeException(
                'The unit lost its ' . $role . ' connection; replacement belongs to a new unit.',
            );
        }
        if (isset($this->connections[$role])) {
            return $this->connections[$role];
        }

        $connection = $this->database->acquire($role);
        $this->connections[$role] = $connection;
        $this->usedConnection = true;

        return $connection;
    }

    /**
     * Tracks a cursor so request cleanup can close it before the connection is
     * considered reusable.
     *
     * @return Cursor<array<string, mixed>>
     */
    public function cursor(QueryBuilder $query): Cursor
    {
        $this->assertOpen();
        $cursor = $query->iterateAssociative();
        $this->cursors[] = $cursor;

        return $cursor;
    }

    /**
     * Retires the affected role after an uncertain failure. The unit refuses to
     * replace it; later work must start a new unit.
     */
    public function evict(Throwable $failure, string $role = 'primary', string $reason = 'query_failed'): void
    {
        $connection = $this->connections[$role] ?? null;
        if ($connection === null) {
            throw new RuntimeException('The unit did not acquire the connection it is evicting.');
        }
        unset($this->connections[$role]);
        $this->lost[$role] = true;
        $this->database->evict($role, $connection, $reason);
    }

    /** @internal Used by the example to assert lazy acquisition. */
    public function usedConnection(): bool
    {
        return $this->usedConnection;
    }

    /**
     * Runs work that changes session state temporarily. Restoration runs in
     * finally; a restoration failure evicts the session and preserves the
     * original failure when one exists.
     *
     * @param Closure(Connection): void $change
     * @param Closure(Connection): void $restore
     * @param Closure(Connection): mixed $work
     */
    public function withTemporarySessionChange(
        Closure $change,
        Closure $restore,
        Closure $work,
        string $role = 'primary',
    ): mixed {
        $connection = $this->connection($role);
        $change($connection);
        $primary = null;

        try {
            return $work($connection);
        } catch (Throwable $failure) {
            $primary = $failure;

            throw $failure;
        } finally {
            try {
                $restore($connection);
            } catch (Throwable $restoreFailure) {
                $this->evict($primary ?? $restoreFailure, $role, 'session_restore_failed');
                if ($primary === null) {
                    throw $restoreFailure;
                }
            }
        }
    }

    /** @internal Called once by WorkerDatabase::request(). */
    public function finish(?Throwable $primary): void
    {
        $this->finished = true;
        $cleanupFailure = $this->closeCursors();
        $cleanupFailure = $this->releaseConnections($primary, $cleanupFailure);

        if ($primary === null && $cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }

    private function closeCursors(): ?Throwable
    {
        $cleanupFailure = null;
        foreach ($this->cursors as $cursor) {
            if ($cursor->isClosed()) {
                continue;
            }
            try {
                $cursor->close();
                $this->database->noteCursorClosed();
            } catch (Throwable $failure) {
                $cleanupFailure ??= $failure;
            }
        }
        $this->cursors = [];

        return $cleanupFailure;
    }

    private function releaseConnections(?Throwable $primary, ?Throwable $cleanupFailure): ?Throwable
    {
        foreach ($this->connections as $role => $connection) {
            unset($this->connections[$role]);
            try {
                $this->database->release($role, $connection, $primary);
            } catch (Throwable $failure) {
                $cleanupFailure ??= $failure;
            }
        }
        $this->connections = [];

        return $cleanupFailure;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new RuntimeException('The request unit already finished; create a new unit for the next request.');
        }
    }
}
