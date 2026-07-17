# Database support

Status: target `0.1.0` support matrix; no combination is certified yet.

Support means compiler coverage, live integration tests, a documented minimum
version, and an ongoing maintenance commitment. Similar protocol behavior or a
successful simple query does not imply support.

## Target `0.1.0` matrix

| Capability | MariaDB 11.8 LTS | MySQL 8 | SQLite 3.39.2+ |
| --- | --- | --- | --- |
| Basic CRUD compilation | Implemented | Implemented | Implemented |
| Inner/left joins | Implemented | Implemented | Implemented |
| Group/order/pagination | Implemented | Implemented | Implemented |
| Savepoints | Implemented | Implemented | Implemented |
| Generated IDs | Implemented | Implemented | Implemented |
| Multi-row insert compilation | Implemented | Implemented | Implemented |
| Lock clauses | Implemented | Implemented | Unsupported |
| Generic upsert | Deferred | Deferred | Deferred |
| Generic DML returning | Deferred | Unsupported shape | Deferred |

Exact MySQL 8 and proxy patch baselines will be published before `0.1.0` after
the reproducible compatibility matrix is complete. The SQLite minimum is
checked at runtime with `sqlite_version()`.

## MariaDB and MySQL

MariaDB and MySQL share protocol and SQL ancestry but remain separate
compatibility targets. The library uses explicit `Driver::MariaDb` and
`Driver::MySql` choices and independently tests:

- prepared statements and binding types;
- affected-row semantics and generated IDs;
- SQL modes and character sets;
- savepoints, implicit commits, and locks;
- functions and syntax used by typed features;
- fetched scalar types and aggregate precision.

Both use `pdo_mysql` and backtick identifier quoting. MySQL shared locks compile
as `FOR SHARE`; MariaDB shared locks compile as `LOCK IN SHARE MODE` for the
initial contract.

The supported default profile uses exception mode, native prepares, buffered
queries, changed-row counts, non-persistent connections, and `utf8mb4` in the
DSN. Emulated or unbuffered modes require explicit configuration and dedicated
tests.

## SQLite

SQLite is a first-class embedded and test target, not a substitute for
MariaDB/MySQL integration tests.

The default connection policy:

- requires SQLite 3.39.2 or later;
- enables and verifies foreign-key enforcement;
- applies a configurable 5,000 ms busy timeout;
- does not silently enable WAL or change journal/synchronous modes;
- reports runtime version and relevant compile options in diagnostics/tests.

SQLite serializes writers. WAL allows readers alongside a writer but does not
provide simultaneous writers. Applications must handle `SQLITE_BUSY` according
to their workload and transaction policy.

SQLite uses type affinity and has no separate Boolean/date storage class.
Applications own Boolean conventions, date/time formats, timezones, decimal
representation, and strict-table choices.

In-memory databases are connection-local and cannot use WAL. File-backed tests
are required for locking, contention, busy-timeout, and journal behavior.

## ProxySQL and MaxScale

Database proxies are transport/routing layers, not SQL dialects. Compatibility
is configuration- and version-sensitive. Public certification requires
reproducible tests for:

- native and emulated prepared statements;
- buffered and unbuffered execution;
- transaction pinning and savepoints;
- generated IDs and affected rows;
- session initialization and reset behavior;
- read-after-write policy;
- connection loss and failover ambiguity;
- active cursor and timeout behavior.

SimpleQuery does not configure routing, discover topology, select replicas, or
guarantee causal consistency. A write or commit interrupted by transport loss
has an unknown outcome and is never retried automatically.

Certified proxy versions and fixture settings will be published in the support
matrix before release. Operators should run maintained proxy versions and
review vendor security advisories independently of this library.

## Unsupported engines

PostgreSQL, SQL Server, Oracle Database, and every other engine are unsupported.
The codebase contains no placeholder drivers, dormant compiler branches, or
third-party dialect mechanism for them.
