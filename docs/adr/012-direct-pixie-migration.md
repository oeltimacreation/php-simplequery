# ADR-012: Direct Pixie migration

- Status: Accepted
- Date: 2026-07-17

## Context

A runtime compatibility facade would add a second public API, preserve
ambiguous behavior, and pressure the new implementation to reproduce known
defects.

## Decision

Applications migrate directly from Pixie to native SimpleQuery APIs. No
namespace alias package, Pixie-shaped facade, runtime adapter, or deprecation
shim is developed.

Familiar fluent patterns are retained only where they fit the new correctness
and typing contracts. Deterministic codemods may assist unambiguous source edits
but must flag uncertain write returns and raw SQL.

## Consequences

- Migration requires explicit application development and tests.
- Insert returns, raw SQL, diagnostics, and transactions require review.
- The core has one API and one versioning surface.
- Intentional differences remain visible and documented.
