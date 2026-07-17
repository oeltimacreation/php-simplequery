<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use PDO;
use PDOException;

final class Connection
{
    private bool $closed = false;

    private int $activeCursors = 0;

    private function __construct(
        private ?PDO $pdoInstance,
        private readonly Driver $selectedDriver,
        private readonly ConnectionOptions $options,
        private readonly ?QueryObserver $observer,
    ) {
    }

    public static function fromPdo(
        PDO $pdo,
        Driver $driver,
        ?ConnectionOptions $options = null,
        ?QueryObserver $observer = null,
    ): self {
        $options ??= new ConnectionOptions();
        $options->validateFor($driver);
        self::validatePdo($pdo, $driver, $options);

        return new self($pdo, $driver, $options, $observer);
    }

    /** @param array<int, mixed> $pdoOptions */
    public static function connect(
        Driver $driver,
        string $dsn,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        array $pdoOptions = [],
        ?ConnectionOptions $connectionOptions = null,
        ?QueryObserver $observer = null,
    ): self {
        $connectionOptions ??= new ConnectionOptions();
        $connectionOptions->validateFor($driver);
        self::validateDsn($driver, $dsn);
        self::validatePdoOptionKeys($pdoOptions);

        $effectiveOptions = self::buildPdoOptions($driver, $pdoOptions, $connectionOptions);
        $declaredOptions = self::effectiveConnectionOptions($driver, $effectiveOptions, $connectionOptions);

        try {
            $pdo = new PDO($dsn, $username, $password, $effectiveOptions);
            if ($driver === Driver::Sqlite) {
                $timeout = $declaredOptions->sqliteBusyTimeoutMilliseconds ?? 5000;
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = ' . $timeout);
            }

            return self::fromPdo($pdo, $driver, $declaredOptions, $observer);
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (PDOException) {
            throw new ConnectionException('Could not establish the database connection.');
        }
    }

    /** @internal Used by the first-party compiler testing toolkit. */
    public static function forCompilation(Driver $driver): self
    {
        return new self(null, $driver, new ConnectionOptions(), null);
    }

    public function table(string|Identifier|QueryBuilder $source, ?string $alias = null): QueryBuilder
    {
        $this->assertCanCreateQuery();

        if ($source instanceof QueryBuilder) {
            if (!$source->belongsTo($this)) {
                throw new InvalidQueryException('A derived source must belong to the same connection.');
            }
            if ($alias === null) {
                throw new InvalidQueryException('A derived source requires an alias.');
            }

            return new QueryBuilder($this, Source::subquery($source->compile(), $alias));
        }

        $identifier = is_string($source) ? Identifier::of($source) : $source;
        if ($identifier->wildcard) {
            throw new InvalidQueryException('A table source cannot be a wildcard.');
        }

        $identifierAlias = $identifier->alias;
        if ($identifierAlias !== null && $alias !== null && $identifierAlias !== $alias) {
            throw new InvalidQueryException('Conflicting table aliases were supplied.');
        }

        return new QueryBuilder(
            $this,
            Source::table($identifier->withoutAlias(), $alias ?? $identifierAlias),
        );
    }

    /** @param list<mixed> $bindings */
    public function raw(string $trustedSql, array $bindings = []): RawExpression
    {
        return new RawExpression($trustedSql, $bindings);
    }

    /** @param array<array-key, mixed> $bindings */
    public function query(string $trustedSql, array $bindings = []): RawQuery
    {
        $this->assertCanCreateQuery();

        return new RawQuery($this, $trustedSql, $bindings);
    }

    public function pdo(): PDO
    {
        return $this->pdoForExecution();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->activeCursors > 0) {
            throw new TransactionStateException('A connection with an active cursor cannot be closed.');
        }
        if ($this->pdoInstance !== null && $this->pdoInstance->inTransaction()) {
            throw new TransactionStateException('A connection with an active transaction cannot be closed.');
        }

        $this->pdoInstance = null;
        $this->closed = true;
    }

    public function driver(): Driver
    {
        return $this->selectedDriver;
    }

    public function connectionOptions(): ConnectionOptions
    {
        return $this->options;
    }

    /** @internal */
    public function observer(): ?QueryObserver
    {
        return $this->observer;
    }

    /** @internal */
    public function pdoForExecution(): PDO
    {
        if ($this->closed) {
            throw new ConnectionException('The database connection is closed.');
        }
        if ($this->pdoInstance === null) {
            throw new ConnectionException('A compiler-only connection has no PDO instance.');
        }

        return $this->pdoInstance;
    }

    /** @internal */
    public function registerCursor(): void
    {
        ++$this->activeCursors;
    }

    /** @internal */
    public function releaseCursor(): void
    {
        if ($this->activeCursors > 0) {
            --$this->activeCursors;
        }
    }

    /** @internal */
    public function transactionDepth(): int
    {
        return $this->pdoInstance !== null && $this->pdoInstance->inTransaction() ? 1 : 0;
    }

    /** @internal */
    public function requireTransactionForLock(): void
    {
        if (!$this->pdoForExecution()->inTransaction()) {
            throw new TransactionStateException('A row-lock query requires an active transaction.');
        }
    }

    private function assertCanCreateQuery(): void
    {
        if ($this->closed) {
            throw new ConnectionException('The database connection is closed.');
        }
    }

    /** @param array<mixed> $pdoOptions */
    private static function validatePdoOptionKeys(array $pdoOptions): void
    {
        foreach (array_keys($pdoOptions) as $key) {
            if (!is_int($key)) {
                throw new ConfigurationException('PDO option keys must be integer PDO attributes.');
            }
        }
    }

    private static function validateDsn(Driver $driver, string $dsn): void
    {
        $expectedPrefix = $driver === Driver::Sqlite ? 'sqlite:' : 'mysql:';
        if (!str_starts_with(strtolower($dsn), $expectedPrefix)) {
            throw new ConfigurationException('The DSN does not match the selected driver.');
        }
        if ($driver === Driver::Sqlite) {
            return;
        }

        preg_match_all('/(?:^|;)charset=([^;]*)/i', $dsn, $matches);
        $charsets = $matches[1];
        if (count($charsets) !== 1 || strtolower($charsets[0]) !== 'utf8mb4') {
            throw new ConfigurationException('MariaDB/MySQL DSNs must specify exactly one charset=utf8mb4 option.');
        }
    }

    /**
     * @param array<int, mixed> $pdoOptions
     * @return array<int, mixed>
     */
    private static function buildPdoOptions(
        Driver $driver,
        array $pdoOptions,
        ConnectionOptions $connectionOptions,
    ): array {
        self::assertOption($pdoOptions, PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION, 'exception mode');
        self::assertOption($pdoOptions, PDO::ATTR_PERSISTENT, false, 'non-persistent connections');
        $pdoOptions[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $pdoOptions[PDO::ATTR_PERSISTENT] = false;

        if ($driver === Driver::Sqlite) {
            if (array_key_exists(PDO::ATTR_EMULATE_PREPARES, $pdoOptions)) {
                throw new ConfigurationException('Prepare emulation is not a supported SQLite option.');
            }

            return $pdoOptions;
        }

        $emulate = self::requestedBooleanOption(
            $pdoOptions,
            PDO::ATTR_EMULATE_PREPARES,
            $connectionOptions->emulatePrepares,
            false,
            'prepare emulation',
        );
        $buffered = self::requestedBooleanOption(
            $pdoOptions,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
            $connectionOptions->bufferedQueries,
            true,
            'query buffering',
        );
        self::assertOption($pdoOptions, PDO::MYSQL_ATTR_FOUND_ROWS, false, 'changed-row counting');
        $pdoOptions[PDO::ATTR_EMULATE_PREPARES] = $emulate;
        $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = $buffered;
        $pdoOptions[PDO::MYSQL_ATTR_FOUND_ROWS] = false;

        return $pdoOptions;
    }

    /** @param array<int, mixed> $pdoOptions */
    private static function assertOption(array $pdoOptions, int $attribute, mixed $required, string $policy): void
    {
        if (array_key_exists($attribute, $pdoOptions) && $pdoOptions[$attribute] !== $required) {
            throw new ConfigurationException(sprintf('PDO options conflict with required %s.', $policy));
        }
    }

    /** @param array<int, mixed> $pdoOptions */
    private static function requestedBooleanOption(
        array $pdoOptions,
        int $attribute,
        ?bool $declared,
        bool $default,
        string $name,
    ): bool {
        $raw = $pdoOptions[$attribute] ?? null;
        if ($raw !== null && !is_bool($raw)) {
            throw new ConfigurationException(sprintf('The PDO %s option must be Boolean.', $name));
        }
        if ($declared !== null && $raw !== null && $declared !== $raw) {
            throw new ConfigurationException(sprintf('Conflicting %s declarations were supplied.', $name));
        }

        return $declared ?? $raw ?? $default;
    }

    /**
     * @param array<int, mixed> $pdoOptions
     */
    private static function effectiveConnectionOptions(
        Driver $driver,
        array $pdoOptions,
        ConnectionOptions $declared,
    ): ConnectionOptions {
        if ($driver === Driver::Sqlite) {
            return new ConnectionOptions(
                persistent: false,
                sqliteBusyTimeoutMilliseconds: $declared->sqliteBusyTimeoutMilliseconds ?? 5000,
                label: $declared->label,
            );
        }

        return new ConnectionOptions(
            emulatePrepares: (bool) $pdoOptions[PDO::ATTR_EMULATE_PREPARES],
            bufferedQueries: (bool) $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY],
            foundRows: false,
            persistent: false,
            label: $declared->label,
        );
    }

    private static function validatePdo(PDO $pdo, Driver $driver, ConnectionOptions $options): void
    {
        try {
            $actualDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($actualDriver) || $actualDriver !== $driver->pdoDriver()) {
                throw new ConfigurationException(sprintf(
                    'PDO driver %s does not match selected driver %s.',
                    is_scalar($actualDriver) ? (string) $actualDriver : 'unknown',
                    $driver->value,
                ));
            }
            if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                throw new ConfigurationException('PDO exception mode is required.');
            }
            if ((bool) $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
                throw new ConfigurationException('Persistent PDO connections are outside the supported profile.');
            }

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

    private static function validateMySqlFamily(PDO $pdo, ConnectionOptions $options): void
    {
        $emulate = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if ($emulate !== ($options->emulatePrepares ?? false)) {
            throw new ConfigurationException('PDO prepare-emulation state does not match its declaration.');
        }

        $buffered = (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        if ($buffered !== ($options->bufferedQueries ?? true)) {
            throw new ConfigurationException('PDO query-buffering state does not match its declaration.');
        }

        $statement = $pdo->query('SELECT @@character_set_connection');
        $charset = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($charset) || strtolower($charset) !== 'utf8mb4') {
            throw new ConfigurationException('The effective MariaDB/MySQL connection character set must be utf8mb4.');
        }
    }

    private static function validateSqlite(PDO $pdo, int $expectedBusyTimeout): void
    {
        $version = $pdo->query('SELECT sqlite_version()');
        $versionValue = $version === false ? false : $version->fetchColumn();
        if (!is_string($versionValue) || version_compare($versionValue, '3.39.2', '<')) {
            throw new ConfigurationException('SQLite 3.39.2 or later is required.');
        }

        $foreignKeys = $pdo->query('PRAGMA foreign_keys');
        $foreignKeysValue = $foreignKeys === false ? false : $foreignKeys->fetchColumn();
        if ((int) $foreignKeysValue !== 1) {
            throw new ConfigurationException('SQLite foreign-key enforcement must be enabled.');
        }

        $busyTimeout = $pdo->query('PRAGMA busy_timeout');
        $busyTimeoutValue = $busyTimeout === false ? false : $busyTimeout->fetchColumn();
        if ((int) $busyTimeoutValue !== $expectedBusyTimeout) {
            throw new ConfigurationException('SQLite busy timeout does not match its declaration.');
        }
    }
}
