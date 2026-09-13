<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery\Internal;

use Oeltima\SimpleQuery\ConnectionOptions;
use Oeltima\SimpleQuery\Driver;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use PDO;
use PDOException;

/**
 * Construction-time connection profile: DSN validation, PDO option merging,
 * and supported-profile checks for injected and connected PDO instances.
 *
 * @internal
 */
final class ConnectionProfile
{
    public static function validateDsn(Driver $driver, string $dsn): void
    {
        if (!str_starts_with(strtolower($dsn), self::dsnPrefix($driver))) {
            throw new ConfigurationException('The DSN does not match the selected driver.');
        }
        if ($driver === Driver::Sqlite) {
            return;
        }

        self::validateMySqlCharset($dsn);
    }

    /** @param array<mixed> $pdoOptions */
    public static function validatePdoOptionKeys(array $pdoOptions): void
    {
        foreach (array_keys($pdoOptions) as $key) {
            if (!is_int($key)) {
                throw new ConfigurationException('PDO option keys must be integer PDO attributes.');
            }
        }
    }

    /**
     * @param array<int, mixed> $pdoOptions
     * @return array<int, mixed>
     */
    public static function buildPdoOptions(
        Driver $driver,
        array $pdoOptions,
        ConnectionOptions $connectionOptions,
    ): array {
        self::assertOption($pdoOptions, PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION, 'exception mode');
        self::assertOption($pdoOptions, PDO::ATTR_PERSISTENT, false, 'non-persistent connections');
        $pdoOptions[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $pdoOptions[PDO::ATTR_PERSISTENT] = false;

        return $driver === Driver::Sqlite
            ? self::sqliteOptions($pdoOptions)
            : self::mysqlFamilyOptions($pdoOptions, $connectionOptions);
    }

    /**
     * @param array<int, mixed> $pdoOptions
     * @return array<int, mixed>
     */
    private static function sqliteOptions(array $pdoOptions): array
    {
        if (array_key_exists(PDO::ATTR_EMULATE_PREPARES, $pdoOptions)) {
            throw new ConfigurationException('Prepare emulation is not a supported SQLite option.');
        }

        return $pdoOptions;
    }

    /**
     * @param array<int, mixed> $pdoOptions
     * @return array<int, mixed>
     */
    private static function mysqlFamilyOptions(array $pdoOptions, ConnectionOptions $connectionOptions): array
    {
        $requests = [
            new RequestedBooleanOption(
                PDO::ATTR_EMULATE_PREPARES,
                $connectionOptions->emulatePrepares,
                false,
                'prepare emulation',
            ),
            new RequestedBooleanOption(
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
                $connectionOptions->bufferedQueries,
                true,
                'query buffering',
            ),
        ];
        foreach ($requests as $request) {
            $pdoOptions[$request->attribute] = self::requestedBooleanOption($pdoOptions, $request);
        }
        self::assertOption($pdoOptions, PDO::MYSQL_ATTR_FOUND_ROWS, false, 'changed-row counting');
        $pdoOptions[PDO::MYSQL_ATTR_FOUND_ROWS] = false;

        return $pdoOptions;
    }

    /** @param array<int, mixed> $pdoOptions */
    private static function requestedBooleanOption(array $pdoOptions, RequestedBooleanOption $request): bool
    {
        $raw = self::booleanShape($pdoOptions[$request->attribute] ?? null, $request->name);
        self::assertNoConflict($raw, $request->declared, $request->name);

        return $request->declared ?? $raw ?? $request->default;
    }

    private static function booleanShape(mixed $raw, string $name): ?bool
    {
        if ($raw !== null && !is_bool($raw)) {
            throw new ConfigurationException(sprintf('The PDO %s option must be Boolean.', $name));
        }

        return $raw;
    }

    private static function assertNoConflict(mixed $raw, ?bool $declared, string $name): void
    {
        if ($declared === null || $raw === null) {
            return;
        }
        if ($declared !== $raw) {
            throw new ConfigurationException(sprintf('Conflicting %s declarations were supplied.', $name));
        }
    }

    /** @param array<int, mixed> $pdoOptions */
    private static function assertOption(array $pdoOptions, int $attribute, mixed $required, string $policy): void
    {
        if (array_key_exists($attribute, $pdoOptions) && $pdoOptions[$attribute] !== $required) {
            throw new ConfigurationException(sprintf('PDO options conflict with required %s.', $policy));
        }
    }
    /**
     * @param array<int, mixed> $pdoOptions
     */
    public static function effectiveConnectionOptions(
        Driver $driver,
        array $pdoOptions,
        ConnectionOptions $declared,
    ): ConnectionOptions {
        return $driver === Driver::Sqlite
            ? self::sqliteConnectionOptions($declared)
            : self::mysqlFamilyConnectionOptions($pdoOptions, $declared);
    }

    private static function sqliteConnectionOptions(ConnectionOptions $declared): ConnectionOptions
    {
        return new ConnectionOptions(
            persistent: false,
            sqliteBusyTimeoutMilliseconds: $declared->sqliteBusyTimeoutMilliseconds ?? 5000,
            label: $declared->label,
        );
    }

    /**
     * @param array<int, mixed> $pdoOptions
     */
    private static function mysqlFamilyConnectionOptions(
        array $pdoOptions,
        ConnectionOptions $declared,
    ): ConnectionOptions {
        return new ConnectionOptions(
            emulatePrepares: (bool) $pdoOptions[PDO::ATTR_EMULATE_PREPARES],
            bufferedQueries: (bool) $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY],
            foundRows: false,
            persistent: false,
            label: $declared->label,
        );
    }

    public static function validatePdo(PDO $pdo, Driver $driver, ConnectionOptions $options): void
    {
        try {
            self::validateDriver($pdo, $driver);
            self::validateRequiredAttributes($pdo);
            if ($driver === Driver::Sqlite) {
                self::validateSqlite($pdo, $options->sqliteBusyTimeoutMilliseconds ?? 5000);
            } else {
                self::validateMySqlFamily($pdo, $options);
            }
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (PDOException) {
            throw new ConfigurationException('Could not validate the PDO supported profile.');
        }
    }

    private static function dsnPrefix(Driver $driver): string
    {
        return $driver === Driver::Sqlite ? 'sqlite:' : 'mysql:';
    }

    private static function validateMySqlCharset(string $dsn): void
    {
        preg_match_all('/(?:^|;)charset=([^;]*)/i', $dsn, $matches);
        $charsets = $matches[1];
        if (count($charsets) !== 1 || strtolower($charsets[0]) !== 'utf8mb4') {
            throw new ConfigurationException('MariaDB/MySQL DSNs must specify exactly one charset=utf8mb4 option.');
        }
    }

    private static function validateDriver(PDO $pdo, Driver $driver): void
    {
        $actualDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($actualDriver) || $actualDriver !== $driver->pdoDriver()) {
            throw new ConfigurationException(sprintf(
                'PDO driver %s does not match selected driver %s.',
                is_scalar($actualDriver) ? (string) $actualDriver : 'unknown',
                $driver->value,
            ));
        }
    }

    private static function validateRequiredAttributes(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new ConfigurationException('PDO exception mode is required.');
        }
        if ((bool) $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new ConfigurationException('Persistent PDO connections are outside the supported profile.');
        }
    }

    private static function validateMySqlFamily(PDO $pdo, ConnectionOptions $options): void
    {
        self::validateEmulatePrepares($pdo, $options);
        self::validateBufferedQueries($pdo, $options);
        self::validateCharacterSet($pdo);
    }

    private static function validateEmulatePrepares(PDO $pdo, ConnectionOptions $options): void
    {
        $emulate = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if ($emulate !== ($options->emulatePrepares ?? false)) {
            throw new ConfigurationException('PDO prepare-emulation state does not match its declaration.');
        }
    }

    private static function validateBufferedQueries(PDO $pdo, ConnectionOptions $options): void
    {
        $buffered = (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        if ($buffered !== ($options->bufferedQueries ?? true)) {
            throw new ConfigurationException('PDO query-buffering state does not match its declaration.');
        }
    }

    private static function validateCharacterSet(PDO $pdo): void
    {
        $statement = $pdo->query('SELECT @@character_set_connection');
        $charset = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($charset) || strtolower($charset) !== 'utf8mb4') {
            throw new ConfigurationException('The effective MariaDB/MySQL connection character set must be utf8mb4.');
        }
    }

    private static function validateSqlite(PDO $pdo, int $expectedBusyTimeout): void
    {
        self::validateSqliteVersion($pdo);
        self::validateForeignKeys($pdo);
        self::validateBusyTimeout($pdo, $expectedBusyTimeout);
    }

    private static function validateSqliteVersion(PDO $pdo): void
    {
        $version = $pdo->query('SELECT sqlite_version()');
        $versionValue = $version === false ? false : $version->fetchColumn();
        if (!is_string($versionValue) || version_compare($versionValue, '3.39.2', '<')) {
            throw new ConfigurationException('SQLite 3.39.2 or later is required.');
        }
    }

    private static function validateForeignKeys(PDO $pdo): void
    {
        $foreignKeys = $pdo->query('PRAGMA foreign_keys');
        $foreignKeysValue = $foreignKeys === false ? false : $foreignKeys->fetchColumn();
        if ((int) $foreignKeysValue !== 1) {
            throw new ConfigurationException('SQLite foreign-key enforcement must be enabled.');
        }
    }

    private static function validateBusyTimeout(PDO $pdo, int $expectedBusyTimeout): void
    {
        $busyTimeout = $pdo->query('PRAGMA busy_timeout');
        $busyTimeoutValue = $busyTimeout === false ? false : $busyTimeout->fetchColumn();
        if ($busyTimeoutValue === false || (int) $busyTimeoutValue !== $expectedBusyTimeout) {
            throw new ConfigurationException('SQLite busy timeout does not match its declaration.');
        }
    }
}
