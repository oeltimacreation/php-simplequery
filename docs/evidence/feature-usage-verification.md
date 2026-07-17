# Edge-feature usage verification

Status: scope decision accepted; rerun the lexical tool when a consumer corpus changes.

| Candidate | Audit result | `0.1.0` decision |
| --- | --- | --- |
| Literal empty `whereIn(..., [])` | No candidate in current executable scan; semantic risk remains general | Compile to constant false; never emit `IN ()` |
| Literal empty `whereNotIn(..., [])` | No candidate in current executable scan | Compile to constant true |
| `updateOrInsert()` | No current executable candidate; research identified Pixie's non-atomic behavior | Do not copy; generic upsert deferred |
| Lock helpers | Direct locking/transaction requirement exists in research; helper spellings vary | Typed update/share/no-wait/skip-locked included; SQLite rejects |
| Named placeholders | No confirmed migration-critical structured use; broad string scans are too noisy | Core uses ordered positional placeholders; raw named placeholders unsupported |
| Cloned builders/subqueries | Clone expressions exist in consumers but no safe assumption that all are builders | Builder clones isolate state; attached subqueries snapshot immediately |
| Broad Pixie constraint exceptions | No confirmed non-vendor catches | Do not reproduce message-derived subclass family |
| Right joins and unions | No meaningful observed use | Deferred |
| `insertIgnore()` / `replace()` | No release-blocking observed use | Excluded from `0.1.0` |

The package maintainer owns compiler/test specifications for accepted behavior.
Application migration owners own any newly discovered raw SQL, vendor feature,
or unsupported write shape. A discovered blocker updates this record and the
relevant ADR before implementation changes.
