<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Generator;
use IteratorAggregate;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;
use Throwable;
use Traversable;

/** @implements IteratorAggregate<int, stdClass|array<string, mixed>> */
final class Cursor implements IteratorAggregate
{
    private bool $started = false;

    private bool $closed = false;

    /** @internal */
    public function __construct(
        private readonly PDOStatement $statement,
        private readonly Connection $connection,
        private readonly CompiledQuery $query,
        private readonly bool $associative,
    ) {
        $this->connection->registerCursor();
    }

    /** @return Traversable<int, stdClass|array<string, mixed>> */
    #[\Override]
    public function getIterator(): Traversable
    {
        if ($this->started) {
            throw new InvalidQueryException('A cursor cannot be iterated more than once.');
        }
        if ($this->closed) {
            throw new InvalidQueryException('A closed cursor cannot be iterated.');
        }
        $this->started = true;

        return $this->rows();
    }

    public function close(): void
    {
        $this->finalize();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Destructor cleanup is best effort only.
        }
    }

    /** @return Generator<int, stdClass|array<string, mixed>> */
    private function rows(): Generator
    {
        $primaryFailure = null;

        try {
            while (!$this->closed) {
                try {
                    $row = $this->statement->fetch($this->associative ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ);
                } catch (PDOException $exception) {
                    throw $this->executionException($exception);
                }

                if ($row === false) {
                    return;
                }
                if ($this->associative) {
                    if (!is_array($row)) {
                        throw QueryExecutionException::invalidResult(
                            'PDO returned an invalid associative cursor row.',
                            $this->query->sql,
                            $this->connection->driver(),
                            $this->connection->connectionOptions()->label,
                        );
                    }
                    foreach ($row as $key => $_value) {
                        if (!is_string($key)) {
                            throw QueryExecutionException::invalidResult(
                                'PDO returned a cursor row with a non-string column name.',
                                $this->query->sql,
                                $this->connection->driver(),
                                $this->connection->connectionOptions()->label,
                            );
                        }
                    }

                    yield $row;
                    continue;
                }
                if (!$row instanceof stdClass) {
                    throw QueryExecutionException::invalidResult(
                        'PDO returned an invalid cursor row.',
                        $this->query->sql,
                        $this->connection->driver(),
                        $this->connection->connectionOptions()->label,
                    );
                }

                yield $row;
            }
        } catch (Throwable $failure) {
            $primaryFailure = $failure;

            throw $failure;
        } finally {
            try {
                $this->finalize();
            } catch (Throwable $cleanupFailure) {
                if ($primaryFailure === null) {
                    throw $cleanupFailure;
                }
            }
        }
    }

    private function finalize(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $cleanupFailure = null;
        try {
            if (!$this->statement->closeCursor()) {
                $cleanupFailure = QueryExecutionException::invalidResult(
                    'PDO could not close the cursor; the connection state is uncertain.',
                    $this->query->sql,
                    $this->connection->driver(),
                    $this->connection->connectionOptions()->label,
                );
            }
        } catch (PDOException $exception) {
            $cleanupFailure = $this->executionException($exception);
        }

        try {
            if ($cleanupFailure !== null) {
                $this->connection->quarantine();
            }
        } finally {
            $this->connection->releaseCursor();
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }

    private function executionException(PDOException $exception): QueryExecutionException
    {
        return QueryExecutionException::fromPdo(
            $exception,
            $this->query->sql,
            $this->connection->driver(),
            $this->connection->connectionOptions()->label,
        );
    }
}
