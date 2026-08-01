<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Closure;
use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Exception\TransactionStateException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Internal\ConnectionProfile;
use Oeltima\SimpleQuery\Internal\Transaction\TransactionManager;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use PDO;
use PDOException;

final class Connection
{
    private bool $closed = false;

    private int $activeCursors = 0;

    private readonly TransactionManager $transactionManager;

    private function __construct(
        private ?PDO $pdoInstance,
        private readonly Driver $selectedDriver,
        private readonly ConnectionOptions $options,
        private readonly ?QueryObserver $observer,
    ) {
        $this->transactionManager = new TransactionManager($this);
    }

    public static function fromPdo(
        PDO $pdo,
        Driver $driver,
        ?ConnectionOptions $options = null,
        ?QueryObserver $observer = null,
    ): self {
        $options ??= new ConnectionOptions();
        $options->validateFor($driver);
        ConnectionProfile::validatePdo($pdo, $driver, $options);

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
        ConnectionProfile::validateDsn($driver, $dsn);
        ConnectionProfile::validatePdoOptionKeys($pdoOptions);

        $effectiveOptions = ConnectionProfile::buildPdoOptions($driver, $pdoOptions, $connectionOptions);
        $declaredOptions = ConnectionProfile::effectiveConnectionOptions(
            $driver,
            $effectiveOptions,
            $connectionOptions,
        );

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

    /** @param list<mixed> $bindings */
    public function query(string $trustedSql, array $bindings = []): RawQuery
    {
        $this->assertCanCreateQuery();

        return new RawQuery($this, $trustedSql, $bindings);
    }

    public function pdo(): PDO
    {
        return $this->pdoForExecution();
    }

    /**
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->transactionManager->run($callback);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->activeCursors > 0) {
            throw new TransactionStateException(
                'A connection with an active cursor cannot be closed.',
                operation: 'close',
                managedDepth: $this->transactionManager->depth(),
                driver: $this->selectedDriver,
                connectionLabel: $this->options->label,
            );
        }
        if ($this->pdoInstance !== null && $this->pdoInstance->inTransaction()) {
            throw new TransactionStateException(
                'A connection with an active transaction cannot be closed.',
                operation: 'close',
                managedDepth: $this->transactionManager->depth(),
                driver: $this->selectedDriver,
                connectionLabel: $this->options->label,
            );
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
        $this->transactionManager->assertUsable();

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
    public function quarantine(): void
    {
        $this->transactionManager->quarantine();
    }

    /** @internal */
    public function transactionDepth(): int
    {
        $managedDepth = $this->transactionManager->depth();
        if ($managedDepth > 0) {
            return $managedDepth;
        }

        return $this->pdoInstance !== null && $this->pdoInstance->inTransaction() ? 1 : 0;
    }

    /** @internal */
    public function hasActiveCursors(): bool
    {
        return $this->activeCursors > 0;
    }

    /** @internal */
    public function requireTransactionForLock(): void
    {
        if (!$this->pdoForExecution()->inTransaction()) {
            throw new TransactionStateException(
                'A row-lock query requires an active transaction.',
                operation: 'lock_query',
                managedDepth: $this->transactionManager->depth(),
                driver: $this->selectedDriver,
                connectionLabel: $this->options->label,
            );
        }
    }

    private function assertCanCreateQuery(): void
    {
        if ($this->closed) {
            throw new ConnectionException('The database connection is closed.');
        }
    }
}
