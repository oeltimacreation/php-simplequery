# ADR-020: SQLite immediate managed transactions

- Status: Accepted
- Date: 2026-07-28

## Context

The first production migration contains ordinary atomic write groups and a
specialized row-lock workflow. Ordinary groups fit the existing managed
callback. The lock workflow uses MySQL row locks in production and SQLite
immediate transactions in tests so writer contention begins at transaction
start rather than at the first write.

PDO has no portable transaction-mode argument. Issuing arbitrary caller-owned
begin SQL through the manager would weaken the closed dialect boundary and
could make physical ownership unverifiable. Keeping every SQLite immediate
scope outside the manager, however, duplicates completion and exception
handling for one well-defined engine capability.

## Decision

Add the closed public `TransactionMode` enum with `Default` and `Immediate`.
`Connection::transaction()` accepts the mode as an optional second argument.

`Default` retains `PDO::beginTransaction()` on every supported engine.
`Immediate` is accepted only for an outer SQLite managed scope and dispatches
the fixed statement `BEGIN IMMEDIATE`. The manager immediately requires
`PDO::inTransaction()` to report physical activity, establishes its generated
root ownership savepoint, and then retains the existing commit, rollback,
cursor, nesting, state-verification, and quarantine behavior. Nested calls use
`Default` and savepoints; a nested call cannot select another physical mode.

MariaDB and MySQL reject `Immediate` with `UnsupportedFeatureException` before
the callback or transaction control begins. Manually issued `BEGIN IMMEDIATE`
remains externally owned and is rejected by the managed callback just like a
transaction started with `PDO::beginTransaction()`.

No other SQLite modes, caller-provided begin SQL, isolation configuration,
external transaction adoption, reconnect, or retry behavior are added.

## Consequences

- SQLite applications can request writer intent while retaining managed
  callback ownership and generated savepoint nesting.
- A busy failure while beginning the transaction is exposed as a
  `TransactionException`; verified physical inactivity permits reuse.
- Supported PHP/SQLite combinations must execute the immediate-mode probe and
  prove PDO transaction-state tracking, PDO commit/rollback, nested savepoints,
  contention evidence, and reuse after a busy begin.
- MySQL-family row locking continues to use the existing typed lock clauses
  inside an ordinary managed transaction.
- Schema/migration control and deliberately external work remain PDO-owned.
