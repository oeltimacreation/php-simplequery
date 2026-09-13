# Database support

Support means compiler coverage, live integration tests, a documented minimum
version, and an ongoing maintenance commitment. Similar protocol behavior or a
successful simple query does not imply support.

## Supported matrix

| Capability | MariaDB 11.8 | MySQL 8.0 | SQLite 3.39.2+ |
| --- | --- | --- | --- |
| Basic CRUD compilation | Implemented | Implemented | Implemented |
| Inner/left joins | Implemented | Implemented | Implemented |
| Group/order/pagination | Implemented | Implemented | Implemented |
| Savepoints | Implemented | Implemented | Implemented |
| Immediate managed transaction | Unsupported | Unsupported | Unsupported |
| Generated IDs | Implemented | Implemented | Implemented |
| Multi-row insert compilation | Implemented | Implemented | Implemented |
| Lock clauses | Implemented | Implemented | Unsupported |
| Generic upsert | Unsupported | Unsupported | Unsupported |
| Generic DML returning | Unsupported | Unsupported | Unsupported |

The direct fixture pairs are MariaDB 11.8.2/11.8.8 and MySQL 8.0.11/8.0.46,
representing the minimum/current supported release within each declared engine
line. ProxySQL 3.0.1 and MaxScale 23.02.17-2 run against the current MariaDB
fixture. The SQLite minimum is checked at runtime with `sqlite_version()`.

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

SQLite immediate begin remains an application-owned direct-PDO escape path.
PDO transaction-state tracking for manual begin differs across supported PHP
versions, so the managed callback does not expose transaction modes.

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

The maintained proxy compatibility fixtures are ProxySQL 3.0.1 and MaxScale
23.02.17-2. They validate the documented single-backend configurations; they
do not certify arbitrary proxy topology or routing policy. Operators should
run maintained proxy versions and review vendor security advisories
independently of this library.

## Unsupported engines

PostgreSQL, SQL Server, Oracle Database, and every other engine are unsupported.
The codebase contains no placeholder drivers, dormant compiler branches, or
third-party dialect mechanism for them.

## Compatibility versus upstream maintenance

The retained floors and fixtures describe package compatibility, not a promise
of upstream security maintenance. Checked on 2026-09-11: PHP lists security
support for PHP 8.2 through 2026-12-31 ([PHP support schedule](https://www.php.net/supported-versions.php)).
Oracle identifies MySQL 8.0.46 as the April 2026 end-of-life release
([MySQL release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/)).
Retaining MySQL 8.0 compatibility does not make it an upstream-maintained target.
MySQL 8.4 qualification and proxy replacements are deferred for this release;
no new target or vendor-maintenance guarantee is implied. Production builds
still need upstream or distributor security maintenance as described in the
[support policy](../../SUPPORT.md).

The Linux minimum-SQLite lane builds the pinned 3.39.2 source with column metadata
and asserts the runtime reported by PDO before executing the SQLite suite and
probes. It does not substitute a version-return test double for live execution.
The service runner captures image digests and proxy binary versions, and runs
behavior, execution and transaction probes in all native/emulated and
buffered/unbuffered combinations. See the dated
[development review](../evidence/0.7-development-review.md) for qualification
results and limitations.
