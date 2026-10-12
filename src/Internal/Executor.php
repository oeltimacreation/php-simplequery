<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Closure;
use Oeltima\SimpleQuery\CompiledQuery;
use Oeltima\SimpleQuery\Connection;
use Oeltima\SimpleQuery\Cursor;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\ParameterType;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;

/** @internal */
final readonly class Executor
{
    /** @return list<stdClass> */
    public function getObjects(Connection $connection, CompiledQuery $query): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->attempt(
            $connection,
            $query,
            ExecutionMode::Result,
            function (PDOStatement $statement) use ($connection, $query): array {
                /** @var list<mixed> $rows */
                $rows = $statement->fetchAll(PDO::FETCH_OBJ);
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        throw $this->invalidResult($connection, 'PDO returned an invalid object result set.', $query);
                    }
                }

                /** @var list<stdClass> $rows */
                return $rows;
            },
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function getAssociative(Connection $connection, CompiledQuery $query): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->attempt(
            $connection,
            $query,
            ExecutionMode::Result,
            fn (PDOStatement $statement): array => $this->fetchAssociativeRows($connection, $statement, $query),
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAssociativeRows(Connection $connection, PDOStatement $statement, CompiledQuery $query): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $this->validateAssociativeRow($connection, $row, $query);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function validateAssociativeRow(Connection $connection, mixed $row, CompiledQuery $query): array
    {
        if (!is_array($row)) {
            throw $this->invalidResult($connection, 'PDO returned an invalid associative result row.', $query);
        }
        $this->assertStringKeys($connection, $row, $query);

        return $row;
    }

    /**
     * @param array<array-key, mixed> $row
     * @phpstan-assert array<string, mixed> $row
     */
    private function assertStringKeys(Connection $connection, array $row, CompiledQuery $query): void
    {
        foreach ($row as $key => $_value) {
            if (!is_string($key)) {
                throw $this->invalidResult($connection, 'PDO returned a non-string column name.', $query);
            }
        }
    }

    public function firstObject(Connection $connection, CompiledQuery $query): ?stdClass
    {
        return $this->attempt(
            $connection,
            $query,
            ExecutionMode::Result,
            function (PDOStatement $statement) use ($connection, $query): ?stdClass {
                $row = $statement->fetch(PDO::FETCH_OBJ);
                if ($row === false) {
                    return null;
                }
                if (!$row instanceof stdClass) {
                    throw $this->invalidResult($connection, 'PDO returned an invalid object row.', $query);
                }

                return $row;
            },
        );
    }

    /** @return array<string, mixed>|null */
    public function firstAssociative(Connection $connection, CompiledQuery $query): ?array
    {
        return $this->attempt(
            $connection,
            $query,
            ExecutionMode::Result,
            function (PDOStatement $statement) use ($connection, $query): ?array {
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    return null;
                }

                return $this->validateAssociativeRow($connection, $row, $query);
            },
        );
    }

    /** @return Cursor<stdClass> */
    public function objectCursor(Connection $connection, CompiledQuery $query): Cursor
    {
        $cursor = $this->attempt(
            $connection,
            $query,
            ExecutionMode::Cursor,
            fn (PDOStatement $statement): Cursor => Cursor::objects(
                $statement,
                $connection,
                $query,
            ),
        );

        return $cursor;
    }

    /** @return Cursor<array<string, mixed>> */
    public function associativeCursor(Connection $connection, CompiledQuery $query): Cursor
    {
        $cursor = $this->attempt(
            $connection,
            $query,
            ExecutionMode::Cursor,
            fn (PDOStatement $statement): Cursor => Cursor::associative(
                $statement,
                $connection,
                $query,
            ),
        );

        return $cursor;
    }

    public function affectedRows(Connection $connection, CompiledQuery $query): int
    {
        return $this->attempt(
            $connection,
            $query,
            ExecutionMode::ObservedWrite,
            static fn (PDOStatement $statement): int => $statement->rowCount(),
        );
    }

    public function insertGetId(Connection $connection, CompiledQuery $query): string
    {
        return $this->attempt(
            $connection,
            $query,
            ExecutionMode::ObservedWrite,
            function (PDOStatement $statement, PDO $pdo) use ($connection, $query): string {
                $id = $pdo->lastInsertId();
                if ($id === false || $id === '' || $id === '0') {
                    throw $this->invalidResult(
                        $connection,
                        'The database did not provide a generated ID for the insert.',
                        $query,
                    );
                }

                return $id;
            },
        );
    }

    public function scalar(Connection $connection, CompiledQuery $query): mixed
    {
        return $this->attempt(
            $connection,
            $query,
            ExecutionMode::Result,
            static fn (PDOStatement $statement): mixed => $statement->fetchColumn(),
        );
    }

    /**
     * @template T
     * @param Closure(PDOStatement, PDO): T $operation
     * @return T
     */
    private function attempt(
        Connection $connection,
        CompiledQuery $query,
        ExecutionMode $mode,
        Closure $operation,
    ): mixed {
        $affectedRowsMeaningful = $mode === ExecutionMode::ObservedWrite;
        $retainStatement = $mode === ExecutionMode::Cursor;
        $pdo = $connection->pdoForExecution();
        $observer = $connection->observer();
        $startedAt = $observer === null ? 0 : hrtime(true);
        $observation = ExecutionObservation::begin($connection, $query, $observer, $startedAt);
        $statement = null;
        $cleanupAttempted = false;

        try {
            $prepared = $pdo->prepare($query->sql);
            if (!$prepared instanceof PDOStatement) {
                throw $this->invalidResult($connection, 'PDO could not prepare the statement.', $query);
            }
            $statement = $prepared;
            $this->bindAndExecute($connection, $statement, $query);

            $result = $operation($statement, $pdo);
            $affectedRows = null;
            if ($observer !== null && $affectedRowsMeaningful) {
                $affectedRows = is_int($result) ? $result : $this->optionalAffectedRows($statement);
            }
            if (!$retainStatement) {
                $cleanupAttempted = true;
                $statement->closeCursor();
            }
            $this->notify($observation, true, $affectedRows);

            return $result;
        } catch (PDOException $exception) {
            $this->notify($observation, false, null);

            throw QueryExecutionException::fromPdo(
                $exception,
                $query->sql,
                $connection->driver(),
                $connection->connectionOptions()->label,
            );
        } catch (QueryExecutionException $exception) {
            $this->notify($observation, false, null);

            throw $exception;
        } finally {
            if (!$retainStatement && !$cleanupAttempted) {
                $this->closeStatementQuietly($statement);
            }
        }
    }

    private function bindAndExecute(Connection $connection, PDOStatement $statement, CompiledQuery $query): void
    {
        foreach ($query->bindings as $index => $binding) {
            if (!$statement->bindValue($index + 1, $binding->value, self::pdoType($binding->type))) {
                throw $this->invalidResult($connection, 'PDO could not bind a statement parameter.', $query);
            }
        }
        if (!$statement->execute()) {
            throw $this->invalidResult($connection, 'PDO could not execute the statement.', $query);
        }
    }

    private function notify(
        ?ExecutionObservation $observation,
        bool $successful,
        ?int $affectedRows,
    ): void {
        if ($observation === null) {
            return;
        }

        $observation->notify($successful, $affectedRows);
    }

    private function closeStatementQuietly(?PDOStatement $statement): void
    {
        if (!$statement instanceof PDOStatement) {
            return;
        }

        try {
            $statement->closeCursor();
        } catch (PDOException) {
            // A prior result or failure remains authoritative.
        }
    }

    private function optionalAffectedRows(PDOStatement $statement): ?int
    {
        try {
            return $statement->rowCount();
        } catch (\Throwable) {
            // Optional observation metadata cannot replace a successful result.
            return null;
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

    private function invalidResult(
        Connection $connection,
        string $message,
        CompiledQuery $query,
    ): QueryExecutionException {
        return QueryExecutionException::invalidResult(
            $message,
            $query->sql,
            $connection->driver(),
            $connection->connectionOptions()->label,
        );
    }
}
