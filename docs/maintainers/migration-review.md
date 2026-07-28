# Migration review report

Use this report for every application moving to SimpleQuery. Keep confidential
paths, repository names, business identifiers, schema, SQL, and deployment
details in an ignored internal record. Commit only synthetic cases and
anonymized aggregates.

## Generate the candidate report

Run from this repository against a read-only checkout or workspace:

```bash
php tools/audit-consumers.php /path/to/workspace \
  --mode=simplequery \
  --deterministic > adoption-audit.json
```

The default report replaces file paths with stable source tokens. For a private
working report only, add `--include-paths`; never commit that output. The audit
does not edit the consumer. It is a lexical candidate scan, so it cannot prove
allowlisting, transaction nesting, runtime reachability, query safety, or
behavioral parity.

## Required report header

```text
Consumer profile: <synthetic identifier>
Migration revision: <internal reference only>
SimpleQuery version: <version>
Engines and exact versions: <non-secret public evidence or internal reference>
Audit schema: <schema_version>
Reviewers and date: <names may remain internal>
Decision: ready | ready with accepted escape paths | blocked
```

## Resolution checklist

Every non-zero candidate must have an owner and one of: rewritten, covered by a
synthetic parity test, accepted as an application-owned escape path, or blocked.

- [ ] Raw boundaries: classify projections, expression-to-value predicates,
  complete vendor predicates, join expressions, order/group/HAVING expressions,
  and raw queries. Confirm all values remain bindings.
- [ ] Generated IDs: classify `insertGetId()`, affected-row `insert()`, split
  `lastInsertId()` reads, delayed reads, and intervening statements. Rewrite any
  generated-ID dependency to an immediate unambiguous operation.
- [ ] Transaction ownership: classify managed callbacks, direct PDO begin/
  commit/rollback, direct SQL control, queries inside externally owned
  transactions, nested managed calls, cursor lifetime, and lock behavior.
- [ ] Dynamic identifiers: record the application allowlist or replace dynamic
  strings with explicit `Identifier` construction after allowlisting.
- [ ] Result shapes: confirm object versus associative hydration, nullable
  `first()` behavior, aggregate scalar types, duplicate columns, and writable
  object expectations.
- [ ] Batching: review repeated query loops, write batches, and `insertMany()`
  opportunities using production-shaped synthetic volumes.
- [ ] Streaming: decide whether full hydration or a one-shot cursor owns the
  result lifetime; confirm early close and transaction/connection boundaries.
- [ ] Live engines: list direct and proxy profiles actually exercised. Do not
  infer MariaDB, MySQL, SQLite, or proxy compatibility from another engine.
- [ ] Performance: preserve index-friendly ranges and joins; compare only
  identical result digests and environments.
- [ ] Exceptions and diagnostics: retain redaction, inspect structured driver
  evidence only where needed, and avoid application dependence on SQL text.

## Finding table

| Source token | Category | Intended behavior | Resolution | Synthetic evidence | Owner |
|---|---|---|---|---|---|
| `source-…` | generated-ID timing | immediate generated ID | rewrite | fixture/test reference | reviewer |

Finish with explicit accepted risks, blockers, unsupported features, live
probe references, and confirmation that no private material was copied into the
library repository.
