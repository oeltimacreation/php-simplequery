# Testing architecture and commands

Status: accepted test architecture.

## Commands

```bash
composer install
composer check                 # fast; no containers or network database
composer test:coverage         # writes Clover and HTML coverage
composer coverage:check        # PCOV: 90 overall and 95 compiler line gates
composer test:coverage:branch  # Xdebug path/branch report
composer coverage:check:branch # 80 overall and 90 compiler branch gates
composer phpstan:consumer      # independent public-contract inference
composer public-api:check      # reviewed reflection-signature manifest
composer docs:check            # relative Markdown link targets
composer examples:check        # executable compiler and SQLite examples
composer probe:sqlite          # JSON PDO/SQLite evidence
composer probe:execution -- sqlite    # public executor smoke
composer probe:transaction -- sqlite  # managed transaction state matrix
bash tools/database-probes/run-services.sh  # complete direct/proxy behavior and execution matrix
php tools/database-probes/ambiguous-write.php proxysql  # operator-controlled failure window
composer benchmark             # complete deterministic SQLite benchmark suite
composer benchmark:soak        # repeated compile/lifecycle stress
```

`composer check` is the clean-checkout contract. Data-driven compiler
fixtures, ordinary PHPUnit, PHPCS, PHPStan, public-contract, documentation,
example, and repository checks provide the maintained quality baseline. The
service-backed command starts only the exact synthetic Docker fixtures in
[`compose.yaml`](../../tools/database-probes/compose.yaml), records native/emulated and
buffered/unbuffered reports plus public execution and transaction smokes under
the ignored `tools/database-probes/results/` directory, prints a summary, and
removes containers, networks, and volumes. Direct CI supplies exact version
variables for MariaDB 11.8.2/11.8.8 and MySQL 8.0.11/8.0.46 and archives each
fixture pair separately; scheduled proxy runs use the current pair.

An intentional public signature change is reviewed by running `composer
public-api:update` and committing the resulting manifest diff. Documentation
validation scans maintained root and `docs/` Markdown only, so external URLs
and generated benchmark, probe, coverage, vendor, and analysis output are not
treated as local targets.

## Naming and placement

- tests use `testMethodScenarioExpectedBehavior` or a sentence-style method
  whose subject and expected behavior are unambiguous;
- `tests/Unit` contains isolated public/internal behavior;
- `tests/Compiler` contains independent golden SQL and ordered-binding tests;
- `tests/Integration/{MariaDb,MySql,SQLite,Proxy}` owns live behavior;
- `tests/Compatibility` owns runtime/minimum-version behavior;
- `tests/Consumer` owns no-dev and external-project fixtures;
- migration fixtures and evidence are historical characterization records;
- `tests/Fixtures/Contracts` is versioned executable contract data;
- `tests/Fixtures/Migration` is synthetic migration characterization data;
- `tools/database-probes` owns probe commands, fixtures, and their private
  support classes.
- `tools/database-probes` owns the maintained engine, proxy, SQLite, execution,
  transaction, and contention probes.

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
[the evidence index](../evidence/README.md). A report observation may be:

- `observed`: the probe ran and recorded behavior without asserting a portable
  outcome;
- `passed`: an invariant was asserted and passed;
- `failed`: the reproduction itself failed;
- `skipped`: a declared capability was not applicable.

An observation is not a support certification until the matching deployment
inventory entry is verified and linked to an archived report.

## Coverage

Coverage is scoped to `src/`; probes and tests do not inflate product coverage.
PCOV enforces 90% line overall and 95% line for `Internal/Compiler`. A separate
Xdebug path-coverage run enforces 80% branch overall and 90% compiler branch.
When a threshold is configured, an absent Clover metric is a failure. Paths
are reported for hotspot guidance without a percentage floor. Every documented
transaction state/failure edge remains mandatory regardless of percentages.

## CI mapping

Pull-request CI runs PHP 8.2–8.5 SQLite tests, strict quality checks, a
lowest-dependency job, minimum/current MariaDB/MySQL direct probes, a no-dev
installation, an independent external-consumer PHPStan run, enforced Xdebug
branch/path coverage, the compact SQLite benchmark suite, and fresh-process
labeled historical comparisons.
The scheduled/manual proxy workflow runs the exact direct/proxy fixtures plus
live comparisons and four concurrent soak workers, then uploads redacted JSON
artifacts. Release
certification additionally runs:

```bash
php scripts/verify-repository.php --certify
```

That command intentionally fails while an engine/proxy deployment version,
configuration, or probe artifact remains unverified.

## Compiler fixture relationships

`GoldenQueryCases` owns executable case IDs. Every case has a golden fixture
with exact SQL, ordered binding values and an explicit equal-length type list,
including empty lists. The feature manifest maps each feature and declared
dialect to those IDs or an executable unsupported-feature rejection. The
validator rejects missing dialects, unknown/duplicate IDs, missing types,
cardinality mismatches and orphan references. Expected SQL is reviewed fixture
input and is never regenerated from the compiler being tested.

Run `bash tools/database-probes/run-minimum-sqlite.sh` on Linux to build and
exercise actual SQLite 3.39.2 with the current PDO extension. It verifies the
pinned source checksum, enables column metadata required by the PDO build and
fails if dynamic loading does not select the requested version. Build artifacts
remain under `build/sqlite-minimum/`; reports remain in ignored probe results.
This checks database-runtime compatibility independently of the PHP-floor lane.
