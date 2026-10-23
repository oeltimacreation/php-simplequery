# ADR-026: Reject structured locked child queries

- Status: Accepted
- Date: 2026-10-23

## Context

Child builders are snapshotted as detached SQL and bindings. A child lock remains
in that SQL, but its parent's top-level lock state does not preserve the child's
active-transaction precondition. Derived sources, predicates, scalar/count
rewrites, and writes would all need internal execution requirements to support
that composition consistently. Parsing trusted SQL is outside the contract.

## Decision

Reject a structured child containing a row-lock clause before attaching it as a
derived source or any `IN`/`NOT IN` operand, including condition groups. Throw
`UnsupportedFeatureException` before mutating the parent or preparing SQL.
Existing owner and required-alias validation still precede child compilation.

The child snapshot compilation seam is internal and carries no new public
capability field. Standalone locking reads remain supported under their existing
transaction and engine requirements. Detached ordinary compilation remains
available; changing the original child after successful attachment cannot
alter an existing parent's snapshot.

## Consequences

- Previously accepted locked composition is rejected in 0.10.0 and requires
  upgrade guidance.
- No terminal can accidentally bypass the rule by using an aggregate, count,
  cursor, update, or delete after attachment.
- Trusted raw queries remain application-owned SQL with explicit transaction
  and engine/proxy qualification; they are not parsed for locks.
- Supporting locked composition later requires a separate accepted internal
  execution-requirement design and live evidence across every claimed engine.
