# Insert return audit

Status: core return decision closed; nine-checkout migration candidates
classified.

The executable lexical audit command is:

```bash
php tools/audit-consumers.php /path/to/workspace
```

On 2026-07-17, the read-only scan of nine Pecee Pixie 4.15.8/4.16.3 consumer
checkouts produced 648 `insert()` call candidates:

| Syntactic use | Calls | Migration classification |
| --- | ---: | --- |
| Assigned locally | 162 | Generated-ID consumer candidate; map to `insertGetId()` only after the variable's use is confirmed. |
| Returned directly | 77 | Passed through to another layer; caller contract must choose ID versus affected rows explicitly. |
| Truthiness condition | 9 | Rewrite around explicit ID or affected-row semantics. |
| Return ignored or terminal chained | 400 | Return ignored candidate; map to `insert()` unless manual review finds a wrapper contract. |
| Total | 648 | Every return-sensitive candidate has an owning migration category. |

The public [audit baseline](consumer-audit-baseline.json) omits repository
paths and source. Batch insert assumptions are reviewed separately because
`insertMany()` returns affected rows and never infers generated IDs.

This scan is intentionally conservative lexical evidence, not a PHP data-flow
proof and includes tests, scripts, and checked-in support copies. A migration
PR must resolve the 162 assigned, 77 passed-through, and nine truthiness
candidates at their application boundary. The package decision itself is not
open:

- `insert()` and `insertMany()` return affected rows as `int`;
- `insertGetId()` returns an immediately captured generated ID as `string`;
- no polymorphic return, truthiness compatibility mode, or generated batch-ID
  inference exists.

Application migration owners own call-site confirmation; the package
maintainer owns the return types and characterization fixtures.
