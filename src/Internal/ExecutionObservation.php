<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use Oeltima\SimpleQuery\ParameterType;

/** @internal */
final readonly class ExecutionObservation
{
    private function __construct(
        private Connection $connection,
        private CompiledQuery $query,
        private QueryObserver $observer,
        private int|float $startedAt,
    ) {
    }

    public static function begin(
        Connection $connection,
        CompiledQuery $query,
        ?QueryObserver $observer,
        int|float $startedAt,
    ): ?self {
        if ($observer === null) {
            return null;
        }

        return new self($connection, $query, $observer, $startedAt);
    }

    public function notify(bool $successful, ?int $affectedRows): void
    {
        try {
            $this->observer->queryExecuted(new QueryExecution(
                $this->query->sql,
                array_map(static fn ($binding): ParameterType => $binding->type, $this->query->bindings),
                (hrtime(true) - $this->startedAt) / 1_000_000,
                $successful,
                $affectedRows,
                $this->connection->driver(),
                $this->connection->connectionOptions()->label,
                $this->connection->transactionDepth(),
            ));
        } catch (\Throwable) {
            // Observation is deliberately non-interfering.
        }
    }
}
