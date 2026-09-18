<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use PDO;

/**
 * PDO connection that counts transaction-control calls for cost attribution.
 *
 * The wrapper delegates every call to the real driver; it never changes
 * behavior. Counting can be reset around a measured window.
 */
final class CountingPdo extends PDO
{
    /** @var array<string, int> */
    public array $controlCounts = [];

    public int $inspectionCalls = 0;

    private bool $counting = false;

    /** @param array<int, bool|int|string> $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function startCounting(): void
    {
        $this->controlCounts = [];
        $this->inspectionCalls = 0;
        $this->counting = true;
    }

    public function stopCounting(): void
    {
        $this->counting = false;
    }

    public function count(string $name): int
    {
        return $this->controlCounts[$name] ?? 0;
    }

    #[\Override]
    public function beginTransaction(): bool
    {
        $this->record('begin');

        return parent::beginTransaction();
    }

    #[\Override]
    public function commit(): bool
    {
        $this->record('commit');

        return parent::commit();
    }

    #[\Override]
    public function rollBack(): bool
    {
        $this->record('rollback');

        return parent::rollBack();
    }

    #[\Override]
    public function inTransaction(): bool
    {
        if ($this->counting) {
            ++$this->inspectionCalls;
        }

        return parent::inTransaction();
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $this->record(match (true) {
            str_starts_with($statement, 'RELEASE SAVEPOINT ') => 'release_savepoint',
            str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ') => 'rollback_to_savepoint',
            str_starts_with($statement, 'SAVEPOINT ') => 'savepoint',
            default => 'exec_other',
        });

        return parent::exec($statement);
    }

    private function record(string $name): void
    {
        if (!$this->counting) {
            return;
        }
        $this->controlCounts[$name] = ($this->controlCounts[$name] ?? 0) + 1;
    }
}
