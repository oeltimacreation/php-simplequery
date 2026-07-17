<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Testing;

use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\Observability\QueryObserver;

final class RecordingQueryObserver implements QueryObserver
{
    /** @var list<QueryExecution> */
    private array $executions = [];

    public function __construct(private readonly int $capacity = 100)
    {
        if ($this->capacity < 1) {
            throw new InvalidQueryException('Recording observer capacity must be positive.');
        }
    }

    #[\Override]
    public function queryExecuted(QueryExecution $execution): void
    {
        $this->executions[] = $execution;
        if (count($this->executions) > $this->capacity) {
            array_shift($this->executions);
        }
    }

    /** @return list<QueryExecution> */
    public function executions(): array
    {
        return $this->executions;
    }

    public function clear(): void
    {
        $this->executions = [];
    }
}
