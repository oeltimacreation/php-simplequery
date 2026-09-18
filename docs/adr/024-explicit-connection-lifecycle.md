# ADR-024: Explicit connection lifecycle and terminal discard

- Status: Accepted
- Date: 2026-09-18

## Context

The first worker-mode consumer retains ordinary non-persistent connections
between sequential requests. Idle expiry left dead wrappers cached; its
recovery holder needs internal state methods and suppresses strict-close
failures. Connection loss must not cause statement replay or implicitly move
builders and transactions to a new owner.

## Decision

Add three explicit public methods:

- `isClosed(): bool` reads wrapper state, without consulting PDO.
- `isReusable(): bool` returns false for closed, compiler-only, quarantined,
  managed-transaction, tracked-cursor, or PDO-reported physical-transaction
  state. Otherwise it returns true. It issues no health-check SQL and does not
  prove transport liveness, clean session settings, or untracked PDO resource
  cleanup. A failed PDO transaction inspection quarantines the wrapper and
  throws `TransactionStateException` with operation `is_reusable`, retained
  control evidence, and `connectionUnusable=true`.
- `discard(): void` permanently marks the wrapper closed and releases its PDO
  reference without inspecting state or issuing cleanup/transaction SQL. It is
  idempotent and accepts abandoned transaction/cursor state. It never connects,
  commits, rolls back explicitly, retries, or transfers artifacts to a replacement.

Keep `close()` strict and unchanged: active physical transactions and tracked
cursors prevent closure; PDO inspection failures still propagate. Discard is
terminal abandonment, not a relaxed successful transaction completion.
Managed callback completion after discard cannot succeed or issue further
transaction-control SQL. A callback failure remains available in transaction
failure evidence. Nested discard cannot be hidden by catching an inner failure.

Old builders may still compile, preserving their SQL and bindings, but cannot
execute. Raw queries and models retain their old owner. A cursor cannot fetch
another row after discard: advancing it throws `ConnectionException` and
attempts normal cursor cleanup, preserving the primary failure if cleanup also
fails. Explicit cursor close remains available and idempotent; discard itself
does not enumerate or close cursors. Rows already returned cannot be revoked.

The library cannot revoke escaped PDO/statement references. Releasing its own
reference proves neither physical disconnection nor rollback; callbacks and
cursors can retain PDO resources. Applications must release or resolve those
resources and treat uncertain writes as uncertain. PDO transaction inspection
also has driver/runtime limits for manually issued transaction SQL (especially
SQLite); direct-PDO state remains application-owned.

Applications may retain a non-persistent connection across sequential execution
units only with exclusive ownership, completed transaction/cursor work, and
application-verified session cleanup. Acquire/pin once per unit and release
all models/builders/cursors at its boundary. Replacement belongs at that
boundary, before new work, through an explicit factory. `isReusable()` is one
necessary local check, not sufficient evidence that reuse is safe. Fresh
connections per unit remain the simplest default. Concurrent sharing,
persistent PDO, pooling, automatic reconnect, and retries remain unsupported.

This refines the worker ownership guidance under ADR-008 and ADR-016 without
changing transaction guards, core routing, or the observation contract.

## Consequences

- Applications can retire failed wrappers without depending on internal APIs.
- No default query gains a ping, connection policy, credential retention, or
  automatic recovery. Explicit liveness checks cannot guarantee the next query.
- Cursor advancement gains a local closed-state check. Cleanup and escaped
  resource limitations must remain visible in examples and upgrade guidance.
- Direct MariaDB/MySQL idle-expiry and disconnect probes complement controlled
  PDO and SQLite lifecycle tests; they do not qualify FrankenPHP or proxy
  recovery policy by inference.
- Connection-construction diagnostics remain a separate decision (W2).
