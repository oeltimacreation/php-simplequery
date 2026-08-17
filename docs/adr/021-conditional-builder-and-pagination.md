# ADR-021: Conditional builder and pagination helpers

- Status: Accepted
- Date: 2026-08-17

## Context

Applications commonly need to add optional clauses and calculate a page
window while retaining SimpleQuery's mutable-builder lifecycle. Adding a
small helper family is preferable to requiring application code to duplicate
branching around every query.

## Decision

`QueryBuilder` exposes:

```php
when(mixed $value, Closure $callback): self
unless(mixed $value, Closure $callback): self
forPage(int $page, int $perPage): self
```

`when()` invokes its callback for a truthy value and `unless()` invokes it for
a falsey value. Each callback receives only the current builder, callback
return values are ignored, and callback exceptions propagate unchanged. Both
methods return the same builder instance.

`forPage()` is strict and 1-based. It rejects non-positive page or page-size
values and integer offset overflow before changing state. It replaces the
existing limit and offset through the same mutation semantics as the explicit
`limit()` and `offset()` methods. It does not add ordering, execute a count,
or provide cursor pagination.

The helpers are available only on `QueryBuilder`; they are not added to
`ConditionGroup`, `Cursor`, or internal extension surfaces.

## Consequences

- Optional clauses remain fluent without introducing a conditional query AST.
- Page windows compile through the existing dialect pagination path.
- Callers remain responsible for deterministic ordering and total-count UX.
- The public API manifest, guides, consumer analysis, and behavior tests must
  cover the new methods.
