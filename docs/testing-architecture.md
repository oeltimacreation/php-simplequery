# Testing architecture and commands

Status: accepted test architecture.

## Commands

```bash
composer install
composer check                 # fast; no containers or network database
composer test:coverage         # writes Clover and HTML coverage
composer coverage:check        # 90/80 overall, 95/90 compiler gates
composer examples:check        # executable compiler and SQLite examples
composer probe:sqlite          # JSON PDO/SQLite evidence
composer probe:execution -- sqlite    # public executor smoke
composer probe:transaction -- sqlite  # managed transaction state matrix
composer probe:migration -- sqlite    # synthetic migration slice smoke
composer migration:check       # deterministic change/ambiguity report
composer benchmark:migration   # direct-PDO result/timing comparison
bash tools/database-probes/run-services.sh  # complete direct/proxy behavior and execution matrix
php tools/database-probes/ambiguous-write.php proxysql  # operator-controlled failure window
composer benchmark             # PDO-only control benchmark
```

`composer check` is the clean-checkout contract. The service-backed command
starts only the exact synthetic Docker fixtures in
[`compose.yaml`](../tools/database-probes/compose.yaml), records native/emulated and
buffered/unbuffered reports plus public execution and transaction smokes under
the ignored `tools/database-probes/results/` directory. It also runs the
library-owned migration slices through every target, prints a summary, and
removes containers, networks, and volumes.

## Naming and placement

- tests use `testMethodScenarioExpectedBehavior` or a sentence-style method
  whose subject and expected behavior are unambiguous;
- `tests/Unit` contains isolated public/internal behavior;
- `tests/Compiler` contains independent golden SQL and ordered-binding tests;
- `tests/Integration/{MariaDb,MySql,SQLite,Proxy}` owns live behavior;
- `tests/Compatibility` owns runtime/minimum-version behavior;
- `tests/Consumer` owns no-dev and external-project fixtures;
- `tests/Migration` owns synthetic native-API slices and automation refusal
  checks;
- `tests/Fixtures/Contracts` is versioned executable contract data;
- `tests/Fixtures/Migration` is synthetic migration characterization data;
- `tools/database-probes` owns probe commands, fixtures, and their private
  support classes.
- `tools/migration` owns deterministic analysis/reporting helpers that are
  development-only and never mutate application files.

The suite uses synthetic tables prefixed `sq_probe_`. Every fixture creates its
own random table name and removes it in `finally`. File-backed SQLite fixtures
use an operating-system temporary file and remove the database, WAL, and SHM
files. Service fixtures use disposable Docker volumes. Tests must not depend on
execution order or a prior dirty database.

## Diagnostics and secrets

Failures report target, engine, PHP/PDO/client/server version, prepare and
buffer mode, SQLSTATE, driver code, placeholder SQL or probe name, and setup
context. They do not report passwords, DSNs containing credentials, binding
values, customer data, hostnames, or production topology. Direct engine output
is the control for every proxy comparison.

Probe reports use schema version 1 and the record format in
[the evidence index](evidence/README.md). A report observation may be:

- `observed`: the probe ran and recorded behavior without asserting a portable
  outcome;
- `passed`: an invariant was asserted and passed;
- `failed`: the reproduction itself failed;
- `skipped`: a declared capability was not applicable.

An observation is not a support certification until the matching deployment
inventory entry is verified and linked to an archived report.

## Coverage

Coverage is scoped to `src/`; probes and tests do not inflate product coverage.
Before implementation PHP files exist, the checker confirms that thresholds
are armed and exits successfully. Once source exists it enforces 90% line and
80% branch overall, plus 95% line and 90% branch for `Internal/Compiler`. Every
documented transaction state/failure edge remains mandatory regardless of
percentages.

## CI mapping

Pull-request CI runs PHP 8.2–8.5 SQLite tests, strict quality checks, a
lowest-dependency job, MariaDB/MySQL direct probes, a no-dev installation, and
the benchmark control. The scheduled/manual proxy workflow runs the exact
ProxySQL and MaxScale fixtures and uploads redacted JSON artifacts. Release
certification additionally runs:

```bash
php scripts/verify-repository.php --certify
```

That command intentionally fails while an engine/proxy deployment version,
configuration, or probe artifact remains unverified.
