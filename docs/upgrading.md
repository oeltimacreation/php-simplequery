# Upgrading

This guide records actionable steps for moving between SimpleQuery release
lines. The first public release is `0.1.0`.

## ZeroVer expectations

- `0.y.0` may contain documented breaking changes.
- `0.y.z` patch releases should remain compatible within that minor line,
  except for urgent security or data-integrity fixes.
- Consumers should pin a tested minor line such as `~0.1.0`.
- Changelog entries are not a substitute for migration instructions; every
  breaking change must be documented here.

## Upgrade checklist

For each future upgrade:

1. read the changelog and this guide;
2. review runtime, extension, database, and proxy support floors;
3. run static analysis and application compile assertions;
4. run SQLite and live database integration tests;
5. verify write-return and aggregate scalar expectations;
6. exercise transaction, cursor, and failure paths;
7. review generated SQL for raw or dialect-specific queries;
8. deploy through the application's normal staged rollout.

## Installing 0.1.0

```bash
composer require oeltimacreation/php-simplequery:^0.1
```

`0.1.0` requires PHP 8.2+, `ext-pdo`, and either `pdo_sqlite` or `pdo_mysql`.
Select `Driver::MariaDb`, `Driver::MySql`, or `Driver::Sqlite` explicitly. See
[getting started](getting-started.md) for connection examples and
[database support](database-support.md) for engine floors.

## Upgrading from 0.1 to 0.2

Non-count scalar aggregate terminals no longer accept builders with
`distinct()`, `groupBy()`, or `having()`. These shapes previously compiled and
could silently return only the first aggregate row. Fetch explicit grouped
aggregate projections when multiple results are intended; grouped/distinct
`count()` remains supported.

Treat cursor cleanup and transaction-control exceptions as connection-lifetime
boundaries. A false or throwing PDO cursor close and an uncertain nested
savepoint creation now quarantine the connection. Failed transaction begin is
recoverable only when SimpleQuery can verify an inactive physical transaction.
Discard a quarantined connection rather than retrying work on it.

## Future upgrade sections

Version-specific instructions will be added under headings such as:

```text
## Upgrading from 0.x to 1.0
```

Published release tags are immutable. Corrections are issued as new patch
releases.
