# PHP SimpleQuery

PHP SimpleQuery is a small, framework-agnostic PDO query builder and execution
library for PHP 8.2 and later.

> [!IMPORTANT]
> The repository foundation and executable PDO/engine probe suite exist, but
> no public query-builder implementation or release exists yet. The documented
> API is the target contract for `0.1.0` and becomes usable only after the
> implementation and release gates in the roadmap are complete.

The project focuses on predictable SQL compilation, ordered typed bindings,
explicit connection ownership, safe nested transactions, and honest database
support. It is not an ORM, schema manager, connection pool, retry engine, or
general database abstraction platform.

## Planned highlights

- mutable fluent builders with private typed state;
- a fresh builder for every `Connection::table()` call;
- non-mutating terminal operations and deterministic compilation;
- positional placeholders with ordered, explicitly typed bindings;
- writable `stdClass` rows by default and associative alternatives;
- affected-row writes and a separate generated-ID terminal;
- savepoint-backed nested transactions with strict ownership checks;
- explicit identifier, value, subquery, and trusted-raw-SQL boundaries;
- first-class MariaDB 11.8 LTS, MySQL 8, and SQLite 3 support;
- no global/default connection, hidden reconnect, or automatic write replay.

## Planned package

```text
Composer package: oeltimacreation/php-simplequery
PHP namespace:    Oeltima\SimpleQuery
PHP requirement:  ^8.2
License:          MIT
First release:    0.1.0
```

Maintainers can bootstrap the development environment with:

```bash
composer install
composer check
composer probe:sqlite
bash tools/database-probes/run-services.sh
```

Until `0.1.0`, do not depend on the repository as a working query-builder
library.

## Documentation

- [Documentation index](docs/index.md)
- [Getting started](docs/getting-started.md)
- [Query builder contract](docs/query-builder.md)
- [Results and writes](docs/results-and-writes.md)
- [Raw SQL and security](docs/raw-sql-and-security.md)
- [Transactions](docs/transactions.md)
- [Database support](docs/database-support.md)
- [Concurrency and workers](docs/concurrency-and-workers.md)
- [Testing applications](docs/testing-applications.md)
- [Migrating from Pixie](docs/migrating-from-pixie.md)
- [Architecture](docs/architecture.md)
- [Public API contract](docs/public-api.md)
- [Testing architecture](docs/testing-architecture.md)
- [Compatibility evidence](docs/evidence/README.md)
- [Roadmap](docs/roadmap.md)
- [Architecture decisions](docs/adr/README.md)

## Project policies

- [Support policy](SUPPORT.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- [Upgrading](docs/upgrading.md)

## Scope

SimpleQuery deliberately does not provide ORM entities, relationships,
repositories, schema migrations, read/write routing, connection pooling,
transparent retries, query caching, arbitrary class hydration, middleware,
compiler plugins, or third-party dialect extensions.

PostgreSQL, SQL Server, Oracle Database, and other engines are outside the
current product scope. Raw SQL may still happen to execute elsewhere, but that
does not constitute support.

## License

PHP SimpleQuery is released under the [MIT License](LICENSE).
