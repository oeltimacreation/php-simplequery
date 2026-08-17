<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Closure;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Observability\QueryExecution;
use Oeltima\SimpleQuery\ParameterType;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;

/** @internal */
final readonly class Executor
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<stdClass> */
    public function getObjects(CompiledQuery $query): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->attempt(
            $query,
            false,
            false,
            function (PDOStatement $statement) use ($query): array {
                /** @var list<mixed> $rows */
                $rows = $statement->fetchAll(PDO::FETCH_OBJ);
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        throw $this->invalidResult('PDO returned an invalid object result set.', $query);
                    }
                }

                /** @var list<stdClass> $rows */
                return $rows;
            },
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function getAssociative(CompiledQuery $query): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->attempt(
            $query,
            false,
            false,
            fn (PDOStatement $statement): array => $this->fetchAssociativeRows($statement, $query),
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAssociativeRows(PDOStatement $statement, CompiledQuery $query): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $this->validateAssociativeRow($row, $query);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function validateAssociativeRow(mixed $row, CompiledQuery $query): array
    {
        if (!is_array($row)) {
            throw $this->invalidResult('PDO returned an invalid associative result row.', $query);
        }
        $this->assertStringKeys($row, $query);

        return $row;
    }

    /**
     * @param array<array-key, mixed> $row
     * @phpstan-assert array<string, mixed> $row
     */
    private function assertStringKeys(array $row, CompiledQuery $query): void
    {
        foreach (array_keys($row) as $key) {
            if (!is_string($key)) {
                throw $this->invalidResult('PDO returned a non-string column name.', $query);
            }
        }
    }

    public function firstObject(CompiledQuery $query): ?stdClass
    {
        return $this->attempt(
            $query,
            false,
            false,
            function (PDOStatement $statement) use ($query): ?stdClass {
                $row = $statement->fetch(PDO::FETCH_OBJ);
                if ($row === false) {
                    return null;
                }
                if (!$row instanceof stdClass) {
                    throw $this->invalidResult('PDO returned an invalid object row.', $query);
                }

                return $row;
            },
        );
    }

    /** @return array<string, mixed>|null */
    public function firstAssociative(CompiledQuery $query): ?array
    {
        return $this->attempt(
            $query,
            false,
            false,
            function (PDOStatement $statement) use ($query): ?array {
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    return null;
                }

                return $this->validateAssociativeRow($row, $query);
            },
        );
    }

    /** @return Cursor<stdClass> */
    public function objectCursor(CompiledQuery $query): Cursor
    {
        $cursor = $this->attempt(
            $query,
            false,
            true,
            fn (PDOStatement $statement): Cursor => Cursor::objects(
                $statement,
                $this->connection,
                $query,
            ),
        );

        return $cursor;
    }

    /** @return Cursor<array<string, mixed>> */
    public function associativeCursor(CompiledQuery $query): Cursor
    {
        $cursor = $this->attempt(
            $query,
            false,
            true,
            fn (PDOStatement $statement): Cursor => Cursor::associative(
                $statement,
                $this->connection,
                $query,
            ),
        );

        return $cursor;
    }

    public function affectedRows(CompiledQuery $query): int
    {
        return $this->attempt(
            $query,
            true,
            false,
            static fn (PDOStatement $statement): int => $statement->rowCount(),
        );
    }

    public function insertGetId(CompiledQuery $query): string
    {
        return $this->attempt(
            $query,
            true,
            false,
            function (PDOStatement $statement, PDO $pdo) use ($query): string {
                $id = $pdo->lastInsertId();
                if ($id === false || $id === '' || $id === '0') {
                    throw $this->invalidResult(
                        'The database did not provide a generated ID for the insert.',
                        $query,
                    );
                }

                return $id;
            },
        );
    }

    public function scalar(CompiledQuery $query): mixed
    {
        return $this->attempt(
            $query,
            false,
            false,
            static fn (PDOStatement $statement): mixed => $statement->fetchColumn(),
        );
    }

    /**
     * @template T
     * @param Closure(PDOStatement, PDO): T $operation
     * @return T
     */
    private function attempt(
        CompiledQuery $query,
        bool $affectedRowsMeaningful,
        bool $retainStatement,
        Closure $operation,
    ): mixed {
        $pdo = $this->connection->pdoForExecution();
        $observer = $this->connection->observer();
        $startedAt = $observer === null ? 0 : hrtime(true);
        $statement = null;

        try {
            $prepared = $pdo->prepare($query->sql);
            if (!$prepared instanceof PDOStatement) {
                throw $this->invalidResult('PDO could not prepare the statement.', $query);
            }
            $statement = $prepared;
            foreach ($query->bindings as $index => $binding) {
                if (!$statement->bindValue($index + 1, $binding->value, self::pdoType($binding->type))) {
                    throw $this->invalidResult('PDO could not bind a statement parameter.', $query);
                }
            }
            if (!$statement->execute()) {
                throw $this->invalidResult('PDO could not execute the statement.', $query);
            }

            $result = $operation($statement, $pdo);
            $affectedRows = $affectedRowsMeaningful ? $statement->rowCount() : null;
            if (!$retainStatement) {
                $statement->closeCursor();
            }
            $this->notify($query, $startedAt, true, $affectedRows);

            return $result;
        } catch (PDOException $exception) {
            $this->notify($query, $startedAt, false, null);

            throw QueryExecutionException::fromPdo(
                $exception,
                $query->sql,
                $this->connection->driver(),
                $this->connection->connectionOptions()->label,
            );
        } catch (QueryExecutionException $exception) {
            $this->notify($query, $startedAt, false, null);

            throw $exception;
        } finally {
            if (!$retainStatement && $statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (PDOException) {
                    // A prior result or failure remains authoritative.
                }
            }
        }
    }

    private static function pdoType(ParameterType $type): int
    {
        return match ($type) {
            ParameterType::Null => PDO::PARAM_NULL,
            ParameterType::Integer => PDO::PARAM_INT,
            ParameterType::String, ParameterType::Binary => PDO::PARAM_STR,
            ParameterType::Lob => PDO::PARAM_LOB,
            ParameterType::Auto => throw new \LogicException('Compiled bindings cannot be automatic.'),
        };
    }

    private function notify(
        CompiledQuery $query,
        int|float $startedAt,
        bool $successful,
        ?int $affectedRows,
    ): void {
        $observer = $this->connection->observer();
        if ($observer === null) {
            return;
        }

        try {
            $observer->queryExecuted(new QueryExecution(
                $query->sql,
                array_map(static fn ($binding): ParameterType => $binding->type, $query->bindings),
                (hrtime(true) - $startedAt) / 1_000_000,
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

    private function invalidResult(string $message, CompiledQuery $query): QueryExecutionException
    {
        return QueryExecutionException::invalidResult(
            $message,
            $query->sql,
            $this->connection->driver(),
            $this->connection->connectionOptions()->label,
        );
    }
}
