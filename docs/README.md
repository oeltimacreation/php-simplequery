# PHP SimpleQuery documentation

PHP SimpleQuery is a focused PDO query builder for MariaDB, MySQL, and SQLite.
Start with the [getting-started guide](guides/getting-started.md) and the
runnable [examples](../examples/README.md).

## Guides

Read these in order when you are new to the library:

1. [Getting started](guides/getting-started.md)
2. [Query builder](guides/query-builder.md)
3. [Results and writes](guides/results-and-writes.md)
4. [Transactions](guides/transactions.md)
5. [Raw SQL and security](guides/raw-sql-and-security.md)

Application-focused guides:

- [Observability](guides/observability.md)
- [Performance and streaming](guides/performance-and-streaming.md)
- [Concurrency and workers](guides/concurrency-and-workers.md)
- [Testing applications](guides/testing-applications.md)
- [Migrating from Pixie](guides/migrating-from-pixie.md)
- [Upgrading](guides/upgrading.md)

## Reference

- [Public API contract](reference/public-api.md)
- [Database support](reference/database-support.md)
- [Architecture](reference/architecture.md)
- [PDO, engine, and proxy quirks](reference/pdo-engine-proxy-quirks.md)

Reference pages describe the current released contract. Unsupported features
are stated as unsupported; possible future work belongs in an active release
plan, not in reference documentation.

## Project records

- [Maintainer documentation](maintainers/README.md)
- [0.7 development plan](plans/0.7.md)
- [Architecture decisions](adr/README.md)
- [Compatibility and release evidence](evidence/README.md)

ADRs and evidence are durable historical records. Plans are temporary working
documents: keep only the active release plan, then retain its outcome in the
changelog and evidence records when the release is complete.
