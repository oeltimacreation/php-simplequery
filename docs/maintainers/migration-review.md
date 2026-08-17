# Migration review checklist

Use this checklist for an application moving from Pixie to SimpleQuery. Keep
confidential paths, repository names, business identifiers, schema, SQL, and
deployment details in a private application record. Commit only synthetic
cases and anonymized aggregate evidence.

## Required review

- [ ] Record the application, SimpleQuery, PHP, PDO, database, and proxy
      versions privately.
- [ ] Characterize imports, connection construction, builder ownership, and
      all direct-PDO boundaries.
- [ ] Classify every insert as generated-ID, affected-row, ignored, truthiness,
      pass-through, or batch behavior.
- [ ] Review raw projections, predicates, joins, grouping, ordering, and full
      queries for binding order and identifier allowlisting.
- [ ] Review transaction ownership, savepoints, cursors, lock behavior, and
      rollback failure paths.
- [ ] Compare object, associative, scalar, cursor, aggregate, pagination, and
      duplicate-column result shapes.
- [ ] Run static analysis, compile assertions, SQLite tests, and each required
      direct/proxy engine path.
- [ ] Record accepted unsupported features, risks, owners, rollout criteria,
      rollback steps, and post-deployment observations.

## Explicit native contracts

`insert()` and `insertMany()` return affected rows; `insertGetId()` returns an
immediately captured string ID. Values are positional bindings, identifiers
are structured or allowlisted, raw SQL is trusted application code, and
transactions are never implicitly adopted or replayed.

SimpleQuery does not certify an external application migration. The application
owner is responsible for semantic review and production rollout evidence.
