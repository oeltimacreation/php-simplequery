# PDO, engine, and proxy quirks

This catalogue records portability boundaries. Exact fixture/deployment
inventory, primary-source dossiers, and executable matrices are maintained in
the [compatibility evidence records](evidence/README.md).

## PDO

- PDO provides a common client API, not normalized SQL semantics.
- Identifiers and SQL syntax cannot be value-bound.
- Attribute availability and readback vary by driver.
- `rowCount()` is never used to count `SELECT` rows.
- `lastInsertId()` must be captured immediately on the same connection.
- transaction state can be invalidated by engine-specific implicit commits.
- `ATTR_TIMEOUT` is not treated as a portable statement deadline.

## MariaDB and MySQL

- they are separately selected and tested despite sharing `pdo_mysql`;
- SQL modes, collations, JSON, functions, locks, upserts, generated IDs, and
  affected rows may differ;
- DDL may implicitly commit;
- changed rows are the default affected-row policy;
- native prepares and buffering behavior require direct and proxy tests;
- transport loss during a write or commit creates an ambiguous outcome.

## SQLite

- foreign keys are connection-specific and must be enabled and verified;
- one writer proceeds at a time, including in WAL mode;
- file-backed contention can produce `SQLITE_BUSY`;
- type affinity does not provide separate Boolean/date storage classes;
- runtime behavior and limits depend on the linked SQLite build;
- in-memory databases are connection-local and cannot use WAL;
- parameter limits are discovered from the runtime rather than assumed.

## Proxies

- prepared statements and session state interact with connection multiplexing;
- transactions and session commands can pin backend state;
- read/write splitting may violate read-after-write expectations without an
  explicit causal/stickiness policy;
- replay and delayed retry settings are version/configuration sensitive;
- the library never treats an ambiguous failed write as safe to retry;
- direct database results are the control for every proxy compatibility test.

## Record format

Each accepted deployment-sensitive policy should link:

1. authoritative source and access date;
2. tested runtime/version and non-secret configuration;
3. minimal synthetic reproduction;
4. direct and proxy results where relevant;
5. selected policy and known weakness;
6. portability consequence;
7. automated tests;
8. owning ADR.

Do not publish credentials, hostnames, private topology, customer data, or
proprietary query samples in this catalogue.

See [technical references](references.md) for the primary public documentation
used to establish these constraints.
