<?php

declare(strict_types=1);

namespace Oeltima\SimpleQuery;

use Oeltima\SimpleQuery\Exception\ConfigurationException;
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\InvalidQueryException;
use Oeltima\SimpleQuery\Expression\Identifier;
use Oeltima\SimpleQuery\Expression\RawExpression;
use Oeltima\SimpleQuery\Internal\Ast\Source;
use Oeltima\SimpleQuery\Observability\QueryObserver;
use PDO;

final class Connection
{
    private function __construct(
        private readonly ?PDO $pdoInstance,
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

        if ($driver === Driver::Sqlite) {
            self::validateSqlite($pdo);
        }

        return new self($pdo, $driver, $options, $observer);
    }

    /** @internal Used by the first-party compiler testing toolkit. */
    public static function forCompilation(Driver $driver): self
    {
        return new self(null, $driver, new ConnectionOptions(), null);
    }

    public function table(string|Identifier|QueryBuilder $source, ?string $alias = null): QueryBuilder
    {
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

    public function pdo(): PDO
    {
        if ($this->pdoInstance === null) {
            throw new ConnectionException('A compiler-only connection has no PDO instance.');
        }

        return $this->pdoInstance;
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

    private static function validateSqlite(PDO $pdo): void
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
    }
}
