<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\DatabaseProbe;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class PdoBehaviorProbe
{
    public function __construct(
        private readonly ProbeTarget $target,
        private readonly PDO $pdo,
    ) {
    }

    public function run(): ProbeReport
    {
        $report = new ProbeReport($this->target->name, $this->target->engine, $this->runtimeMetadata());

        $this->observe($report, 'positional_placeholders', fn (): array => $this->positionalPlaceholders());
        $this->observe($report, 'binding_and_scalar_types', fn (): array => $this->bindingAndScalarTypes());
        $this->observe($report, 'write_returns_and_generated_id', fn (): array => $this->writeReturns());
        $this->observe($report, 'database_error_evidence', fn (): array => $this->databaseErrorEvidence());
        $this->observe($report, 'transactions_and_savepoints', fn (): array => $this->transactionsAndSavepoints());
        $this->observe($report, 'ddl_transaction_state', fn (): array => $this->ddlTransactionState());
        $this->observe($report, 'active_cursor_behavior', fn (): array => $this->activeCursorBehavior());
        $this->observe($report, 'read_after_write', fn (): array => $this->readAfterWrite());
        $this->observe($report, 'session_and_timeout_state', fn (): array => $this->sessionAndTimeoutState());

        if ($this->target->engine === 'sqlite') {
            $this->observe($report, 'sqlite_runtime', fn (): array => $this->sqliteRuntime());
            $this->observe($report, 'sqlite_file_backed', fn (): array => (new SqliteFileProbe())->run());
            $this->observe($report, 'sqlite_contention_stress', fn (): array => (new SqliteContentionProbe())->run());
        } else {
            $this->observe($report, 'prepared_statement_cardinality', fn (): array => $this->preparedCardinality());
            $this->observe($report, 'transaction_connection_identity', fn (): array => $this->transactionIdentity());
            $this->observe($report, 'session_state_isolation', fn (): array => $this->sessionStateIsolation());
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function runtimeMetadata(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'pdo_drivers' => PDO::getAvailableDrivers(),
            'pdo_driver' => $this->attribute(PDO::ATTR_DRIVER_NAME),
            'client_version' => $this->attribute(PDO::ATTR_CLIENT_VERSION),
            'server_version' => $this->attribute(PDO::ATTR_SERVER_VERSION),
            'connection_status' => $this->attribute(PDO::ATTR_CONNECTION_STATUS),
            'emulated_prepares' => $this->attribute(PDO::ATTR_EMULATE_PREPARES),
            'persistent' => $this->attribute(PDO::ATTR_PERSISTENT),
            'stringify_fetches' => $this->attribute(PDO::ATTR_STRINGIFY_FETCHES),
        ];
    }

    /** @return array<string, mixed> */
    private function positionalPlaceholders(): array
    {
        $statement = $this->pdo->prepare("SELECT '?' AS literal_question, ? AS bound_value /* ? */");
        $statement->bindValue(1, 'bound', PDO::PARAM_STR);
        $statement->execute();
        $row = $this->fetchOne($statement);

        return [
            'literal_question' => $row['literal_question'] ?? null,
            'bound_value' => $row['bound_value'] ?? null,
            'placeholder_count' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function bindingAndScalarTypes(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ? AS integer_value, ? AS string_value, ? AS null_value, ? AS lob_value',
        );
        $lob = fopen('php://memory', 'r+b');
        if ($lob === false) {
            throw new PDOException('Unable to open the in-memory LOB fixture.');
        }

        fwrite($lob, "binary\0value");
        rewind($lob);
        $statement->bindValue(1, 42, PDO::PARAM_INT);
        $statement->bindValue(2, '42', PDO::PARAM_STR);
        $statement->bindValue(3, null, PDO::PARAM_NULL);
        $statement->bindValue(4, $lob, PDO::PARAM_LOB);
        $statement->execute();
        $row = $this->fetchOne($statement);
        fclose($lob);

        $boolean = $this->pdo->prepare('SELECT ? AS normalized_boolean');
        $boolean->bindValue(1, 1, PDO::PARAM_INT);
        $boolean->execute();
        $booleanRow = $this->fetchOne($boolean);

        return [
            'values' => $row,
            'php_types' => array_map(static fn (mixed $value): string => get_debug_type($value), $row),
            'normalized_boolean' => $booleanRow['normalized_boolean'] ?? null,
            'normalized_boolean_type' => get_debug_type($booleanRow['normalized_boolean'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function writeReturns(): array
    {
        $table = $this->tableName('writes');
        $this->dropTable($table);
        $this->pdo->exec($this->createTableSql($table));

        try {
            $insert = $this->pdo->prepare(sprintf('INSERT INTO %s (value_text) VALUES (?)', $table));
            $insert->execute(['first']);
            $firstId = $this->pdo->lastInsertId();
            $insertedRows = $insert->rowCount();

            $unchanged = $this->pdo->prepare(sprintf('UPDATE %s SET value_text = ? WHERE id = ?', $table));
            $unchanged->execute(['first', 1]);
            $unchangedRows = $unchanged->rowCount();

            $changed = $this->pdo->prepare(sprintf('UPDATE %s SET value_text = ? WHERE id = ?', $table));
            $changed->execute(['changed', 1]);

            return [
                'insert_row_count' => $insertedRows,
                'last_insert_id' => $firstId,
                'last_insert_id_type' => get_debug_type($firstId),
                'unchanged_update_row_count' => $unchangedRows,
                'changed_update_row_count' => $changed->rowCount(),
            ];
        } finally {
            $this->dropTable($table);
        }
    }

    /** @return array<string, mixed> */
    private function databaseErrorEvidence(): array
    {
        try {
            $this->query('SELECT * FROM simplequery_table_that_must_not_exist');
        } catch (PDOException $exception) {
            return [
                'exception_class' => $exception::class,
                'code' => (string) $exception->getCode(),
                'sqlstate' => isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : null,
                'driver_code_present' => isset($exception->errorInfo[1]),
                'message_redaction_required' => true,
            ];
        }

        throw new PDOException('The invalid-table probe unexpectedly succeeded.');
    }

    /** @return array<string, mixed> */
    private function transactionsAndSavepoints(): array
    {
        $table = $this->tableName('transactions');
        $this->dropTable($table);
        $this->pdo->exec($this->createTableSql($table));

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec(sprintf("INSERT INTO %s (value_text) VALUES ('outer')", $table));
            $this->pdo->exec('SAVEPOINT simplequery_probe_1');
            $this->pdo->exec(sprintf("INSERT INTO %s (value_text) VALUES ('inner')", $table));
            $this->pdo->exec('ROLLBACK TO SAVEPOINT simplequery_probe_1');
            $this->pdo->exec('RELEASE SAVEPOINT simplequery_probe_1');
            $insideCount = $this->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();
            $this->pdo->rollBack();
            $afterCount = $this->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();

            return [
                'inside_count_after_savepoint_rollback' => $insideCount,
                'inside_count_type' => get_debug_type($insideCount),
                'after_outer_rollback_count' => $afterCount,
                'in_transaction_after_rollback' => $this->pdo->inTransaction(),
            ];
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->dropTable($table);
        }
    }

    /** @return array<string, mixed> */
    private function ddlTransactionState(): array
    {
        $dataTable = $this->tableName('ddl_data');
        $ddlTable = $this->tableName('ddl_created');
        $this->dropTable($ddlTable);
        $this->dropTable($dataTable);
        $this->pdo->exec($this->createTableSql($dataTable));

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec(sprintf("INSERT INTO %s (value_text) VALUES ('pending')", $dataTable));
            $this->pdo->exec($this->createTableSql($ddlTable));
            $inTransactionAfterDdl = $this->pdo->inTransaction();

            if ($inTransactionAfterDdl) {
                $this->pdo->rollBack();
            }

            $count = $this->query(sprintf('SELECT COUNT(*) FROM %s', $dataTable))->fetchColumn();

            return [
                'in_transaction_after_ddl' => $inTransactionAfterDdl,
                'pending_row_count_after_cleanup' => $count,
                'implicit_commit_observed' => !$inTransactionAfterDdl,
            ];
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->dropTable($ddlTable);
            $this->dropTable($dataTable);
        }
    }

    /** @return array<string, mixed> */
    private function activeCursorBehavior(): array
    {
        $statement = $this->query('SELECT 1 AS value_number UNION ALL SELECT 2 UNION ALL SELECT 3');
        $first = $statement->fetchColumn();
        $secondStatementSucceeded = false;
        $sqlstate = null;

        try {
            $this->query('SELECT 99')->fetchColumn();
            $secondStatementSucceeded = true;
        } catch (PDOException $exception) {
            $sqlstate = isset($exception->errorInfo[0])
                ? (string) $exception->errorInfo[0]
                : (string) $exception->getCode();
        } finally {
            $statement->closeCursor();
        }

        return [
            'first_value' => $first,
            'second_statement_succeeded' => $secondStatementSucceeded,
            'second_statement_sqlstate' => $sqlstate,
        ];
    }

    /** @return array<string, mixed> */
    private function readAfterWrite(): array
    {
        $table = $this->tableName('read_after_write');
        $this->dropTable($table);
        $this->pdo->exec($this->createTableSql($table));

        try {
            $this->pdo->exec(sprintf("INSERT INTO %s (value_text) VALUES ('visible')", $table));
            $value = $this->query(sprintf('SELECT value_text FROM %s WHERE id = 1', $table))->fetchColumn();

            return [
                'immediate_value' => $value,
                'visible' => $value === 'visible',
                'scope' => 'single logical PDO session; not a causal-read guarantee',
            ];
        } finally {
            $this->dropTable($table);
        }
    }

    /** @return array<string, mixed> */
    private function sessionAndTimeoutState(): array
    {
        $connectionIdentifier = null;
        if ($this->target->engine !== 'sqlite') {
            $connectionIdentifier = $this->query('SELECT CONNECTION_ID()')->fetchColumn();
        }

        return [
            'connection_identifier' => $connectionIdentifier,
            'timeout_attribute' => $this->attribute(PDO::ATTR_TIMEOUT),
            'autocommit_attribute' => $this->attribute(PDO::ATTR_AUTOCOMMIT),
            'server_info' => $this->attribute(PDO::ATTR_SERVER_INFO),
        ];
    }

    /** @return array<string, mixed> */
    private function sqliteRuntime(): array
    {
        $version = $this->query('SELECT sqlite_version()')->fetchColumn();
        $compileOptions = $this->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $foreignKeys = $this->query('PRAGMA foreign_keys')->fetchColumn();
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $busyTimeout = $this->query('PRAGMA busy_timeout')->fetchColumn();
        $journalRequest = $this->query('PRAGMA journal_mode = WAL')->fetchColumn();

        return [
            'sqlite_version' => $version,
            'meets_3_39_2_floor' => is_string($version) && version_compare($version, '3.39.2', '>='),
            'foreign_keys' => $foreignKeys,
            'busy_timeout_ms' => $busyTimeout,
            'memory_database_wal_request_result' => $journalRequest,
            'compile_options' => $compileOptions,
        ];
    }

    /** @return array<string, mixed> */
    private function preparedCardinality(): array
    {
        $lastValue = null;
        for ($index = 1; $index <= 32; ++$index) {
            $statement = $this->pdo->prepare(sprintf('SELECT ? AS value_number /* cardinality-%d */', $index));
            $statement->bindValue(1, $index, PDO::PARAM_INT);
            $statement->execute();
            $row = $this->fetchOne($statement);
            $lastValue = $row['value_number'] ?? null;
        }

        return [
            'unique_statement_count' => 32,
            'last_value' => $lastValue,
            'last_value_type' => get_debug_type($lastValue),
            'statements_closed_explicitly' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function transactionIdentity(): array
    {
        $before = $this->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->pdo->beginTransaction();

        try {
            $during = $this->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->pdo->exec('SAVEPOINT simplequery_identity_probe');
            $atSavepoint = $this->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->pdo->exec('RELEASE SAVEPOINT simplequery_identity_probe');
            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $after = $this->query('SELECT CONNECTION_ID()')->fetchColumn();

        return [
            'before' => $before,
            'during' => $during,
            'at_savepoint' => $atSavepoint,
            'after' => $after,
            'stable_logical_session' => $before === $during && $during === $atSavepoint && $atSavepoint === $after,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionStateIsolation(): array
    {
        $first = $this->target->connect();
        $first->exec("SET @simplequery_probe_session = 'private'");
        $firstValue = $this->queryOn($first, 'SELECT @simplequery_probe_session')->fetchColumn();
        unset($first);

        $second = $this->target->connect();
        $secondValue = $this->queryOn($second, 'SELECT @simplequery_probe_session')->fetchColumn();

        return [
            'first_session_value' => $firstValue,
            'second_session_value' => $secondValue,
            'state_leaked_to_new_logical_connection' => $secondValue !== null,
        ];
    }

    /**
     * @param callable(): array<string, mixed> $probe
     * @param non-empty-string $name
     */
    private function observe(ProbeReport $report, string $name, callable $probe): void
    {
        try {
            $report->observed($name, $probe());
        } catch (Throwable $throwable) {
            $report->failed($name, [
                'exception_class' => $throwable::class,
                'code' => (string) $throwable->getCode(),
                'message' => $this->redact($throwable->getMessage()),
            ]);
        }
    }

    private function attribute(int $attribute): mixed
    {
        try {
            return $this->pdo->getAttribute($attribute);
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function fetchOne(PDOStatement $statement): array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PDOException('The probe query did not return a row.');
        }
        $statement->closeCursor();

        $result = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new PDOException('The associative probe result contained a numeric key.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function query(string $sql): PDOStatement
    {
        return $this->queryOn($this->pdo, $sql);
    }

    private function queryOn(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new PDOException('The probe query did not return a statement.');
        }

        return $statement;
    }

    private function tableName(string $purpose): string
    {
        return sprintf('sq_probe_%s_%s', $purpose, bin2hex(random_bytes(4)));
    }

    private function createTableSql(string $table): string
    {
        if ($this->target->engine === 'sqlite') {
            return sprintf(
                'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, value_text TEXT NOT NULL)',
                $table,
            );
        }

        return sprintf(
            'CREATE TABLE %s '
            . '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, value_text VARCHAR(255) NOT NULL) ENGINE=InnoDB',
            $table,
        );
    }

    private function dropTable(string $table): void
    {
        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $table));
    }

    private function redact(string $message): string
    {
        $redacted = str_replace($this->target->dsn, '[dsn redacted]', $message);
        if ($this->target->password !== null && $this->target->password !== '') {
            $redacted = str_replace($this->target->password, '[password redacted]', $redacted);
        }

        return $redacted;
    }
}
