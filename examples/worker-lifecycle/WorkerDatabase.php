<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Examples\WorkerLifecycle;

use Closure;
use InvalidArgumentException;
use Oeltima\SimpleQuery\Connection;
use RuntimeException;
use Throwable;

/**
 * Application-owned worker database holder.
 *
 * This is a recipe, not a library API. Copy it into the worker bootstrap and
 * adapt the factory, initialization, clock, roles, and idle policy.
 */
final class WorkerDatabase
{
    private const REASON_LABELS = [
        'idle',
        'unusable',
        'query_failed',
        'transaction_unusable',
        'session_restore_failed',
        'cleanup_failed',
    ];

    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var array<string, float> */
    private array $idleSince = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, int> */
    private array $reasons = [];

    private int $acquisitions = 0;

    private int $acquisitionMicroseconds = 0;

    private bool $shutdown = false;

    /**
     * @param Closure(): Connection $factory
     * @param Closure(Connection): void $initialize Runs before a new connection is published.
     * @param Closure(): float $clock Monotonic elapsed seconds.
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly Closure $initialize,
        private readonly Closure $clock,
        private readonly float $idleThresholdSeconds,
    ) {
    }

    /**
     * Runs one request unit. Handlers acquire connections lazily, so a
     * cache-only request never opens a database connection.
     *
     * @param Closure(RequestScope): mixed $handler
     */
    public function request(Closure $handler): mixed
    {
        if ($this->shutdown) {
            throw new RuntimeException('The worker holder was already shut down.');
        }

        $scope = new RequestScope($this);
        $primary = null;
        try {
            return $handler($scope);
        } catch (Throwable $failure) {
            $primary = $failure;

            throw $failure;
        } finally {
            $this->count('units');
            if (!$scope->usedConnection()) {
                $this->count('cache_only_units');
            }
            $scope->finish($primary);
        }
    }

    /**
     * Returns the pinned connection for a role, retiring an idle handle only at
     * this boundary. The caller must already own the unit boundary.
     *
     * @internal
     */
    public function acquire(string $role): Connection
    {
        $current = $this->connections[$role] ?? null;
        if ($current !== null) {
            $now = ($this->clock)();
            try {
                $reusable = $current->isReusable();
            } catch (Throwable) {
                $reusable = false;
            }
            if (!$reusable) {
                $current->discard();
                unset($this->connections[$role], $this->idleSince[$role]);
                $this->count('replaced_unusable');
            } elseif ($now - ($this->idleSince[$role] ?? $now) >= $this->idleThresholdSeconds) {
                $current->discard();
                unset($this->connections[$role], $this->idleSince[$role]);
                $this->count('replaced_idle');
            }
        }

        if (!isset($this->connections[$role])) {
            $started = hrtime(true);
            $connection = ($this->factory)();
            ($this->initialize)($connection);
            $this->connections[$role] = $connection;
            ++$this->acquisitions;
            $this->acquisitionMicroseconds += (int) round((hrtime(true) - $started) / 1_000);
            $this->count('created');
        }

        $this->idleSince[$role] = ($this->clock)();

        return $this->connections[$role];
    }

    /**
     * Reuse is allowed only when the wrapper still reports reusable state at
     * the boundary. Everything else is retired without completing its work.
     *
     * @internal
     */
    public function release(string $role, Connection $connection, ?Throwable $primary): void
    {
        if (($this->connections[$role] ?? null) !== $connection) {
            $connection->discard();
            $this->count('released_stale');

            return;
        }
        unset($this->connections[$role], $this->idleSince[$role]);

        if ($connection->isClosed()) {
            $this->count('released_closed');

            return;
        }

        try {
            $reusable = $connection->isReusable();
        } catch (Throwable $failure) {
            $connection->discard();
            $this->count('cleanup_failed');
            if ($primary === null) {
                throw $failure;
            }

            return;
        }

        if ($reusable) {
            $this->connections[$role] = $connection;
            $this->idleSince[$role] = ($this->clock)();
            $this->count('reused_units');

            return;
        }

        $connection->discard();
        $this->count('discarded_units');
    }

    /**
     * Retires only the role whose owner is uncertain. Other roles keep their
     * state, and no role is connected merely to be discarded.
     *
     * @internal
     */
    public function evict(string $role, Connection $connection, string $reason): void
    {
        if (!in_array($reason, self::REASON_LABELS, true)) {
            throw new InvalidArgumentException('Unknown lifecycle reason label: ' . $reason);
        }
        if (($this->connections[$role] ?? null) === $connection) {
            unset($this->connections[$role], $this->idleSince[$role]);
        }
        $connection->discard();
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        $this->count('evicted');
    }

    /** @internal */
    public function noteCursorClosed(): void
    {
        $this->count('cursors_closed');
    }

    /** @internal */
    public function retained(string $role): bool
    {
        return isset($this->connections[$role]) && !$this->connections[$role]->isClosed();
    }

    /** Closes or discards retained connections; it never opens a new one. */
    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;

        foreach (array_keys($this->connections) as $role) {
            $connection = $this->connections[$role];
            unset($this->connections[$role], $this->idleSince[$role]);
            try {
                if ($connection->isReusable()) {
                    $connection->close();
                    $this->count('shutdown_closed');

                    continue;
                }
            } catch (Throwable) {
                // Local inspection failed; discard instead.
            }
            $connection->discard();
            $this->count('shutdown_discarded');
        }
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters + [
            'units' => 0,
            'cache_only_units' => 0,
            'created' => 0,
            'reused_units' => 0,
            'replaced_idle' => 0,
            'replaced_unusable' => 0,
            'evicted' => 0,
            'discarded_units' => 0,
            'cleanup_failed' => 0,
            'released_stale' => 0,
            'released_closed' => 0,
            'cursors_closed' => 0,
            'shutdown_closed' => 0,
            'shutdown_discarded' => 0,
        ];
    }

    /** @return array<string, int> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /** @return array{acquisitions: int, microseconds: int} */
    public function acquisitionTiming(): array
    {
        return ['acquisitions' => $this->acquisitions, 'microseconds' => $this->acquisitionMicroseconds];
    }

    private function count(string $name): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + 1;
    }
}
