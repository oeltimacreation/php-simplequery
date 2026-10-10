# ADR-025: Non-cloneable resource owners

- Status: Accepted
- Date: 2026-10-10

## Context

PHP's default shallow clone duplicates wrapper fields while retaining shared
PDO, transaction-manager, and statement references. A connection clone has an
independent cursor counter, and a cursor clone can close another cursor's
statement and release its counter. This bypasses lifecycle checks.

## Decision

Make `Connection` and `Cursor` non-cloneable using private `__clone()` methods.
PHP raises `Error` before a duplicate owner is exposed. The original connection
or cursor remains usable and keeps its resource accounting.

`QueryBuilder` cloning remains supported: it copies mutable query state and
retains the same connection owner. Independent execution units require explicit
connection construction; cloning does not create a second PDO session.

## Consequences

- Applications attempting to clone resource owners must change their code.
- Cursor and transaction completion checks continue to use one authoritative
  owner; no shared-owner or connection-pool API is introduced.
- Regression tests cover clone rejection and original-owner usability.
