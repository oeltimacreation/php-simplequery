# Insert return audit

Status: core return decision closed; current-checkout migration candidates classified.

The executable lexical audit command is:

```bash
php tools/audit-consumers.php /path/to/workspace
```

On 2026-07-17, four available consumer checkouts produced 142 single-insert
call candidates:

| Syntactic use | Calls | Migration classification |
| --- | ---: | --- |
| Assigned locally | 38 | Generated-ID consumer candidate; map to `insertGetId()` only after the variable's use is confirmed. |
| Returned directly | 49 | Passed through to another layer; caller contract must choose ID versus affected rows explicitly. |
| Truthiness condition | 0 | No candidate in the executable checkout. |
| Return ignored or terminal chained | 55 | Return ignored; map to `insert()` unless manual review finds a hidden wrapper contract. |
| Total | 142 | Every return-sensitive candidate has an owning migration category. |

The earlier nine-lineage research corpus reached the same API conclusion even
where those repositories are not all present in the current checkout. Batch
insert assumptions are reviewed separately because `insertMany()` returns
affected rows and never infers generated IDs.

This scan is intentionally conservative lexical evidence, not a PHP data-flow
proof. A migration PR must resolve the 38 assigned and 49 passed-through
candidates at their application boundary. The package decision itself is not
open:

- `insert()` and `insertMany()` return affected rows as `int`;
- `insertGetId()` returns an immediately captured generated ID as `string`;
- no polymorphic return, truthiness compatibility mode, or generated batch-ID
  inference exists.

Application migration owners own call-site confirmation; the package
maintainer owns the return types and characterization fixtures.
