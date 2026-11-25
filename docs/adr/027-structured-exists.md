# ADR-027: Structured `EXISTS` in read filters

- Status: Accepted
- Date: 2026-11-21

## Context

Applications can compose a correlated child to select rows with or without
related records. Native `EXISTS` evaluates presence without multiplying parent
rows. A structured predicate can enforce child ownership and supported query
contexts while keeping child values bound. Live tests identified differences
in paginated existence checks and writes that read their own target.
[Engine evaluation](../evidence/0.11-structured-exists.md)

## Decision

- Expose `whereExists(QueryBuilder $subquery)`, `orWhereExists()`,
  `whereNotExists()`, and `orWhereNotExists()` on `QueryBuilder` and
  `ConditionGroup`. They compose in a read's `WHERE`, including nested groups.
- Require the same connection and snapshot the child at attachment. Apply the
  structured-child lock rejection and reject any explicit child limit or
  offset, including `limit(0)`. Reject invalid children before changing the
  parent. Preserve child SQL and ordered typed bindings, including projection
  bindings. The database resolves qualified outer references. [ADR-026](026-locked-child-queries.md)
- Reject structured `EXISTS` in `HAVING` at attachment and in `UPDATE` and
  `DELETE` before preparing SQL. The restriction also applies through nested
  predicate groups, `IN` children, and derived sources inside those children.
  Keep the information in internal snapshots, with no public capability field
  on `CompiledQuery`. JOIN predicates retain their existing API.
- Preserve trusted raw SQL as the escape hatch for caller-qualified SQL. The
  library does not inspect raw text for structured predicates or rewrite the
  child's projection to `SELECT 1`.

## Consequences

- A child mutation after attachment leaves its existing snapshots independent.
  Terminals can reuse the parent and preserve its logical count semantics.
- Read-shape tests cover correlation, nulls, duplicate matches, grouped child
  results, exact SQL and ordered bindings, and rejection without mutation.
- Broader predicate sites or paginated children require a separate decision
  backed by live behavior at the supported engine floors.
