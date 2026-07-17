# PHP SimpleQuery

PHP SimpleQuery is a small, framework-agnostic PDO query builder and execution
library for PHP 8.2 and later.

> [!IMPORTANT]
> Deterministic compilation and the PDO execution/result layer are implemented,
> alongside the PDO/engine probe suite. Managed transaction handling and a
> package release remain pending; do not treat the checkout as a released
> library.

The project focuses on predictable SQL compilation, ordered typed bindings,
explicit connection ownership, safe nested transactions, and honest database
support. It is not an ORM, schema manager, connection pool, retry engine, or
general database abstraction platform.

## Implementation highlights

- mutable fluent builders with private typed state;
- a fresh builder for every `Connection::table()` call;
- non-mutating terminal operations and deterministic compilation;
- positional placeholders with ordered, explicitly typed bindings;
- independent MariaDB, MySQL, and SQLite compiler paths;
- compiler-only testing connections and detached query fixtures;
- explicit identifier, value, subquery, and trusted-raw-SQL boundaries;
- snapshotted subqueries, clone isolation, and deterministic ordered bindings.
- explicit connection policy through injected PDO or DSN construction;
- object/associative hydration, scalar aggregates, writes, and deferred raw SQL;
- tracked one-shot cursors, redacted execution exceptions, and bounded observers.

Managed transactions remain the next accepted `0.1.0` implementation phase.

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
composer examples:check
composer probe:sqlite
composer probe:execution -- sqlite
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
