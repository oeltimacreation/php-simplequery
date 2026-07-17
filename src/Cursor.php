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
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $this->statement->closeCursor();
        } catch (PDOException $exception) {
            throw $this->executionException($exception);
        } finally {
            $this->connection->releaseCursor();
        }
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
                    $associativeRow = [];
                    foreach ($row as $key => $value) {
                        if (!is_string($key)) {
                            throw QueryExecutionException::invalidResult(
                                'PDO returned a cursor row with a non-string column name.',
                                $this->query->sql,
                                $this->connection->driver(),
                                $this->connection->connectionOptions()->label,
                            );
                        }
                        $associativeRow[$key] = $value;
                    }

                    yield $associativeRow;
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
        } finally {
            $this->close();
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
