# ADR-006: Results and write returns

- Status: Accepted
- Date: 2026-07-17

## Context

Polymorphic write returns and mutable fetch modes force callers to inspect
runtime types and make migrations error-prone.

## Decision

Default row results are writable `stdClass` objects with explicit associative
alternatives. No arbitrary class hydration is provided.

Write terminals have one meaning:

- `insert()`, `insertMany()`, `update()`, and `delete()` return affected rows;
- `insertGetId()` returns the generated ID as a string.

`count()` returns a checked PHP integer. Other aggregates preserve driver
scalars to avoid decimal/large-number precision loss.

## Consequences

- Caller types are predictable.
- Generated IDs are not conflated with write success.
- Existing insert call sites require classification during migration.
- Driver-specific affected-row and scalar behavior requires live tests.
