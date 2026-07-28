# ADR-020: Reject managed SQLite transaction modes

- Status: Accepted
- Date: 2026-07-28

## Context

The first production migration contains ordinary atomic write groups and a
specialized row-lock workflow. Ordinary groups fit the existing managed
callback. The lock workflow uses MySQL row locks in production and SQLite
immediate transactions in tests so writer contention begins at transaction
start rather than at the first write.

PDO has no portable transaction-mode argument. A spike implemented a closed
`TransactionMode::Immediate` option by issuing fixed `BEGIN IMMEDIATE` control
SQL through the manager, then requiring `PDO::inTransaction()` before the
callback. It preserved the normal ownership guard, savepoints, cursor checks,
and failure evidence on PHP 8.4 and 8.5.

The supported PHP 8.2 and 8.3 CI jobs disproved portability. Their PDO SQLite
implementations execute `BEGIN IMMEDIATE` but continue to report
`inTransaction() === false`. PDO `commit()` and `rollBack()` therefore cannot
complete that transaction; matching control SQL is required. A manager that
continued despite the false state could neither prove ownership nor reliably
detect external work.

## Decision

Reject a public transaction-mode API. `Connection::transaction()` continues to
use `PDO::beginTransaction()` on every supported engine. Nested managed calls
continue to use generated savepoints.

SQLite immediate transactions remain a deliberate PDO-owned escape path. The
application issues fixed, trusted `BEGIN IMMEDIATE`, `COMMIT`, and `ROLLBACK`
control SQL and must not call `Connection::transaction()` within that scope.
Application code also owns version-sensitive state inspection and recovery.

MySQL-family row locking continues to use typed `forUpdate()` / `forShare()`
queries inside the ordinary managed callback. Schema/migration control and
other externally owned work remain outside managed transactions.

No caller-provided begin SQL, isolation configuration, external transaction
adoption, reconnect, or retry behavior is added.

## Consequences

- The PHP 8.2 runtime floor retains one coherent managed-ownership contract.
- SQLite applications that need immediate writer intent use an explicit,
  engine-specific PDO escape path and test it against their deployed runtime.
- The compatibility transaction probe records whether PDO tracks manual
  immediate begin, selects the matching PDO or control-SQL completion path,
  exercises savepoints and contention, and proves connection reuse.
- Managed begin now verifies that PDO reports physical activity before any
  callback work runs.
- No API implies that a busy, deadlock, timeout, transport, or commit failure is
  safe to retry.
