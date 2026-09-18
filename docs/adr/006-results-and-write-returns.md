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

## Amendment: 2026-09-18 (`0.8.0`)

`min()` and `max()` now declare and validate the same scalar union as `sum()`
and `average()`: they return `int|float|string|null` and throw
`QueryExecutionException` when the driver returns any other value. Previously
they declared `mixed` and returned unvalidated driver output. The change
aligns the signature with the documented "preserve driver scalar" policy;
supported driver values are unchanged.
