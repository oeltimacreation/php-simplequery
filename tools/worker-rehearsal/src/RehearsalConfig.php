<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Tools\WorkerRehearsal;

use InvalidArgumentException;
use Oeltima\SimpleQuery\Driver;
use PDO;

/** Synthetic worker-rehearsal configuration from the process environment. */
final class RehearsalConfig
{
    /** @param array<int, bool|int|string> $pdoOptions */
    private function __construct(
        public readonly Driver $driver,
        public readonly string $dsn,
        public readonly string $username,
        public readonly string $password,
        public readonly array $pdoOptions,
        public readonly string $adminDsn,
        public readonly string $adminUsername,
        public readonly string $adminPassword,
        public readonly string $table,
        public readonly string $limitVariable,
        public readonly string $initializeSql,
        public readonly string $reportLimitSql,
        public readonly float $initializeValue,
        public readonly float $reportLimitValue,
        public readonly float $idleThresholdSeconds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $driverName = self::environment('REHEARSAL_DRIVER', 'mariadb');
        $driver = match ($driverName) {
            'mariadb' => Driver::MariaDb,
            'mysql' => Driver::MySql,
            default => throw new InvalidArgumentException('REHEARSAL_DRIVER must be mariadb or mysql.'),
        };
        $dsn = self::environment('REHEARSAL_DSN', '');
        if ($dsn === '') {
            throw new InvalidArgumentException('REHEARSAL_DSN is required.');
        }
        $table = self::environment('REHEARSAL_TABLE', 'simplequery_rehearsal_jobs');
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('REHEARSAL_TABLE must be a plain lowercase identifier.');
        }
        $isMariaDb = $driver === Driver::MariaDb;
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        return new self(
            driver: $driver,
            dsn: $dsn,
            username: self::environment('REHEARSAL_USER', 'simplequery'),
            password: self::environment('REHEARSAL_PASSWORD', 'simplequery'),
            pdoOptions: $pdoOptions,
            adminDsn: self::environment('REHEARSAL_ADMIN_DSN', $dsn),
            adminUsername: self::environment('REHEARSAL_ADMIN_USER', 'root'),
            adminPassword: self::environment('REHEARSAL_ADMIN_PASSWORD', 'simplequery-root'),
            table: $table,
            limitVariable: $isMariaDb ? 'max_statement_time' : 'max_execution_time',
            initializeSql: $isMariaDb
                ? 'SET SESSION max_statement_time = 2'
                : 'SET SESSION max_execution_time = 2000',
            reportLimitSql: $isMariaDb
                ? 'SET SESSION max_statement_time = 15'
                : 'SET SESSION max_execution_time = 15000',
            initializeValue: $isMariaDb ? 2.0 : 2000.0,
            reportLimitValue: $isMariaDb ? 15.0 : 15000.0,
            idleThresholdSeconds: self::environmentFloat('REHEARSAL_IDLE_SECONDS', 1.0),
        );
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function environmentFloat(string $name, float $default): float
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($name . ' must be numeric.');
        }

        return (float) $value;
    }
}
