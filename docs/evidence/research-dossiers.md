# PDO, engine, SQLite, and proxy research dossiers

Access date for all sources: 2026-07-17. ADR-018 owns the evidence process;
ADRs 005, 006, 008, 009, 011, and 017 own the affected product policies.

## PHP and PDO

- Primary sources: PHP manuals for [PDO](https://www.php.net/manual/en/book.pdo.php),
  [prepare](https://www.php.net/manual/en/pdo.prepare.php),
  [connections](https://www.php.net/manual/en/pdo.connections.php),
  [MySQL PDO](https://www.php.net/manual/en/ref.pdo-mysql.php),
  [SQLite PDO](https://www.php.net/manual/en/ref.pdo-sqlite.php),
  [lastInsertId](https://www.php.net/manual/en/pdo.lastinsertid.php),
  [rowCount](https://www.php.net/manual/en/pdostatement.rowcount.php), and
  [transactions](https://www.php.net/manual/en/pdo.transactions.php).
- Runtime/configuration: PHP 8.2–8.5 CI; `pdo_mysql`/mysqlnd and `pdo_sqlite`;
  exception mode; native/emulated and buffered/unbuffered fixture variants.
- Reproduction: `php tools/database-probes/run.php TARGET`; observations
  `positional_placeholders`, `binding_and_scalar_types`,
  `write_returns_and_generated_id`, `database_error_evidence`,
  `transactions_and_savepoints`, `ddl_transaction_state`, and
  `active_cursor_behavior`.
- Selected policy: ordered positional values; explicit binding type; capture
  IDs immediately; never use SELECT `rowCount()`; no portable statement-timeout
  claim; no hidden reconnect/replay; detect physical transaction state loss.
- Known weakness: PDO does not normalize scalar types, attribute readback,
  generated IDs, affected rows, timeouts, buffering, or transaction edge cases.
- Portability consequence: direct and proxy reports remain independent, and an
  untested attribute combination is outside the supported profile.
- Automated evidence: SQLite tests, direct/proxy workflows, contract
  fixtures, and the JSON probe artifacts.

## MariaDB 11.8 LTS

- Primary sources: MariaDB documentation for
  [MariaDB/MySQL differences](https://mariadb.com/docs/release-notes/community-server/about/compatibility-and-differences/mariadb-vs-mysql-compatibility),
  [prepared statements](https://mariadb.com/docs/server/reference/sql-statements/prepared-statements),
  and the [11.8 LTS announcement](https://mariadb.org/11-8-is-lts/).
- Runtime/configuration: exact public fixture `mariadb:11.8.8`, InnoDB,
  `utf8mb4`, changed-row counts, native/emulated prepares, and
  buffered/unbuffered PDO. Deployment patch/configuration remains inventory.
- Reproduction: direct `mariadb` target plus the `proxysql` and `maxscale`
  targets in `run-services.sh`.
- Selected policy: explicit `Driver::MariaDb`; separate capability ledger from
  MySQL; native prepares and buffering by default; shared lock syntax
  `LOCK IN SHARE MODE`; generated ID as an immediate string.
- Known weakness: SQL modes, collations, functions, JSON, locks, upserts,
  affected rows, implicit commits, and proxy metadata can diverge from MySQL.
- Portability consequence: no feature is accepted by MySQL analogy and raw
  MariaDB syntax remains a deliberate portability boundary.
- Automated evidence: direct matrix, proxy matrix, DDL/savepoint/write/error
  probes, and the planned golden/live compiler tests.

## MySQL 8.0

- Primary sources: MySQL 8.0 manuals for
  [prepared statements](https://dev.mysql.com/doc/refman/8.0/en/sql-prepared-statements.html),
  [SQL modes](https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html),
  [implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html),
  and [savepoints](https://dev.mysql.com/doc/refman/8.0/en/savepoint.html).
- Runtime/configuration: exact public fixture `mysql:8.0.45`, InnoDB,
  `utf8mb4`, changed-row counts, native/emulated prepares, and
  buffered/unbuffered PDO. The deployed minimum patch remains inventory.
- Reproduction: direct `mysql` target in `run-services.sh`.
- Selected policy: explicit `Driver::MySql`; native/buffered default; changed
  rows; shared lock syntax `FOR SHARE`; DDL inside managed transactions is
  unsupported.
- Known weakness: deployment SQL mode/collation and exact 8.0 patch can change
  behavior; transport loss makes a write/commit outcome ambiguous.
- Portability consequence: MySQL and MariaDB retain independent golden/live
  tests even where emitted SQL happens to match.
- Automated evidence: direct behavior matrix and the planned compiler/executor
  live tests.

## SQLite

- Primary sources: SQLite documentation for
  [locking](https://sqlite.org/lockingv3.html), [WAL](https://sqlite.org/wal.html),
  [isolation](https://sqlite.org/isolation.html),
  [foreign keys](https://sqlite.org/foreignkeys.html),
  [type affinity](https://www.sqlite.org/datatype3.html),
  [limits](https://sqlite.org/limits.html),
  [in-memory databases](https://sqlite.org/inmemorydb.html),
  [PRAGMA](https://sqlite.org/pragma.html), and
  [quirks](https://sqlite.org/quirks.html).
- Runtime/configuration: accepted minimum 3.39.2; actual linked runtime and
  compile options recorded per CI/deployment; foreign keys required and read
  back; 5,000 ms default busy timeout; no automatic WAL/synchronous policy.
- Reproduction: `composer probe:sqlite`; `sqlite_runtime` and
  `sqlite_file_backed` observations.
- Selected policy: enforce the runtime floor and foreign keys; file-backed
  contention tests in addition to `:memory:`; Boolean normalization to integer;
  application-owned date/time/decimal and WAL choices.
- Known weakness: one writer at a time; WAL does not create simultaneous
  writers; foreign keys are connection-local; affinity and compile-time limits
  differ from server databases; `:memory:` is connection-local and cannot use
  WAL.
- Portability consequence: SQLite tests cannot replace MariaDB/MySQL live
  tests, and applications must deliberately handle `SQLITE_BUSY`.
- Automated evidence: file-backed PHPUnit probe, every-runtime SQLite
  CI, compile-option report, strict/ordinary type fixture, and busy contention.

## ProxySQL 3.0.1

- Primary sources: ProxySQL documentation for
  [multiplexing](https://proxysql.com/documentation/multiplexing/),
  [prepared statement pooling](https://proxysql.com/documentation/mysql-prepared-statements-pooling-and-caching/),
  [3.0.1](https://github.com/sysown/proxysql/releases/tag/v3.0.1), and the
  [later 3.0 security/stability notice](https://proxysql.com/blog/announcing-proxysql-3-0-9-and-3-1-9/).
- Runtime/configuration: exact fixture `proxysql/proxysql:3.0.1-debian`, one
  MariaDB backend hostgroup, `transaction_persistent=1`, and documented config
  in `tools/database-probes/config/proxysql.cnf`. Deployment rules remain inventory.
- Reproduction: `proxysql` target with all prepare/buffer combinations.
- Selected policy: ProxySQL is not a dialect; transaction routing and session
  behavior are infrastructure-owned; no inferred retry or causal consistency.
- Known weakness: prepared statements, session state, text `PREPARE`, and
  transactions can pin or disable multiplexing. Later documentation may not
  describe 3.0.1 behavior, and newer 3.0 patches include security/correctness
  fixes.
- Portability consequence: 3.0.1 is a required compatibility fixture, not the
  recommended long-term operations patch. Production upgrade review is a gate.
- Automated evidence: scheduled/manual proxy workflow and direct MariaDB
  control reports.

## MaxScale 23.02

- Primary sources: MariaDB's archived
  [MaxScale 23.02 documentation](https://mariadb.com/docs/maxscale/maxscale-archive/archive/mariadb-maxscale-23-02),
  [23.02 changelog](https://mariadb.com/docs/release-notes/maxscale/23.02/23.02-changelog),
  and [read/write splitter contract](https://mariadb.com/docs/maxscale/reference/maxscale-routers/maxscale-readwritesplit).
- Runtime/configuration: exact public fixture `mariadb/maxscale:23.02.17-2`,
  one MariaDB primary, readwritesplit, `transaction_replay=false`,
  `delayed_retry=false`. Exact deployed patch/router configuration remains
  inventory.
- Reproduction: `maxscale` target with all prepare/buffer combinations.
- Selected policy: replay and delayed retry are disabled assumptions; the
  library never retries an ambiguous write; causal read/failover claims require
  the deployment fixture.
- Known weakness: behavior changed inside 23.02, including delayed retry and
  prepared-statement session history. A single-primary smoke fixture does not
  reproduce replica lag or failover.
- Portability consequence: the broad label `23.02` is insufficient for support;
  exact patch and router settings are mandatory certification data.
- Automated evidence: scheduled/manual proxy workflow, direct MariaDB control,
  and deployment-specific release probes.
