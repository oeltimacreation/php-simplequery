<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\WorkerRehearsal;

use Closure;
use Oeltima\SimpleQuery\Connection;
use RuntimeException;
use Throwable;

/**
 * Application-owned worker holder used by the FrankenPHP rehearsal.
 *
 * It mirrors the public guidance: lazy acquisition, per-unit pinning, a
 * monotonic idle boundary, terminal discard on uncertain failure, and bounded
 * counters. It is rehearsal tooling, not a library API.
 */
final class RehearsalHolder
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, int> */
    private array $reasons = [];

    private ?Connection $connection = null;

    private float $idleSince = 0.0;

    private bool $failFactory = false;

    private bool $shutdown = false;

    private int $acquisitions = 0;

    private int $acquisitionMicroseconds = 0;

    /**
     * @param Closure(self): Connection $factory
     * @param Closure(Connection): void $initialize
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly Closure $initialize,
        private readonly float $idleThresholdSeconds,
    ) {
    }

    public function acquire(): Connection
    {
        if ($this->shutdown) {
            throw new RuntimeException('The rehearsal holder was already shut down.');
        }

        $now = hrtime(true) / 1_000_000_000;
        $connection = $this->connection;
        if ($connection !== null) {
            $reusable = false;
            try {
                $reusable = $connection->isReusable();
            } catch (Throwable) {
                $reusable = false;
            }
            if (!$reusable) {
                $connection->discard();
                $connection = null;
                $this->connection = null;
                $this->increment('replaced_unusable');
            } elseif ($now - $this->idleSince >= $this->idleThresholdSeconds) {
                $connection->discard();
                $connection = null;
                $this->connection = null;
                $this->increment('replaced_idle');
            }
        }

        if ($connection === null) {
            $started = hrtime(true);
            $connection = ($this->factory)($this);
            ($this->initialize)($connection);
            $this->connection = $connection;
            ++$this->acquisitions;
            $this->acquisitionMicroseconds += (int) round((hrtime(true) - $started) / 1_000);
            $this->increment('created');
        }

        $this->idleSince = $now;
        $this->increment('units');

        return $connection;
    }

    public function release(?Throwable $primary): void
    {
        $connection = $this->connection;
        if ($connection === null) {
            return;
        }
        $this->connection = null;

        try {
            if ($connection->isClosed()) {
                $this->increment('released_closed');

                return;
            }
            if ($connection->isReusable()) {
                $this->connection = $connection;
                $this->idleSince = hrtime(true) / 1_000_000_000;
                $this->increment('reused_units');

                return;
            }
        } catch (Throwable $failure) {
            $connection->discard();
            $this->increment('cleanup_failed');
            if ($primary === null) {
                throw $failure;
            }

            return;
        }

        $connection->discard();
        $this->increment('discarded_units');
    }

    public function evict(string $reason): void
    {
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        $this->increment('evicted');
        if ($this->connection !== null) {
            $this->connection->discard();
            $this->connection = null;
        }
    }

    public function current(): ?Connection
    {
        return $this->connection;
    }

    public function setFailFactory(bool $fail): void
    {
        $this->failFactory = $fail;
    }

    public function shouldFailFactory(): bool
    {
        return $this->failFactory;
    }

    public function increment(string $counter): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + 1;
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;
        $connection = $this->connection;
        $this->connection = null;
        if ($connection === null) {
            return;
        }
        try {
            if ($connection->isReusable()) {
                $connection->close();
                $this->increment('shutdown_closed');

                return;
            }
        } catch (Throwable) {
            // Fall through to discard.
        }
        $connection->discard();
        $this->increment('shutdown_discarded');
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters + [
            'units' => 0,
            'created' => 0,
            'reused_units' => 0,
            'replaced_idle' => 0,
            'replaced_unusable' => 0,
            'evicted' => 0,
            'discarded_units' => 0,
            'cleanup_failed' => 0,
            'released_closed' => 0,
            'failed_construction' => 0,
            'report_restores' => 0,
            'report_restore_failures' => 0,
            'killed_sessions' => 0,
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
    public function timing(): array
    {
        return ['acquisitions' => $this->acquisitions, 'microseconds' => $this->acquisitionMicroseconds];
    }
}
