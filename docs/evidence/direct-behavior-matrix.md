# Direct PDO and engine behavior matrix

Status: executable fixture matrix; deployment columns require archived reports.

The public fixtures are MariaDB 11.8.8, MySQL 8.0.45, and the linked SQLite
runtime (minimum 3.39.2). Every MariaDB/MySQL row is run with native/emulated
prepares and buffered/unbuffered queries. JSON retains concrete values and PHP
types instead of flattening differences into a false portable result.

The 2026-07-17 bootstrap run completed all 17 reports with no failed probe
observations. On the MariaDB/MySQL controls, generated IDs arrived as strings,
unchanged updates returned zero, DDL ended the physical transaction, buffered
connections allowed the active-cursor second-statement probe, and unbuffered
connections rejected it. The redacted values are retained in
[`fixture-baseline.json`](fixture-baseline.json).

| Behavior | MariaDB control | MySQL control | SQLite control | Owning probe/policy |
| --- | --- | --- | --- | --- |
| Positional placeholders and literal/comment `?` | Recorded | Recorded | Recorded | `positional_placeholders`; ADR-005 |
| Integer/string/null/LOB binding | Recorded with PHP types | Recorded with PHP types | Recorded with PHP types | `binding_and_scalar_types`; ADR-005 |
| Boolean normalization | Bind integer `0`/`1` | Bind integer `0`/`1` | Bind integer `0`/`1` | binding probe; contract fixture |
| Fetched scalar types | Preserve observation | Preserve observation | Preserve observation | binding/write/aggregate probes |
| Generated ID | Immediate PDO string evidence | Immediate PDO string evidence | Immediate PDO string evidence | write probe; ADR-006 |
| Insert/update affected rows | Changed-row default recorded | Changed-row default recorded | Driver result recorded | write probe; ADR-006 |
| SQLSTATE/driver error | Recorded, values redacted | Recorded, values redacted | Recorded, values redacted | error probe; ADR-009 |
| Timeout attribute | Observation only; no deadline claim | Observation only; no deadline claim | Busy timeout separately verified | session/SQLite probes |
| Active cursor / second statement | Per buffer mode | Per buffer mode | Recorded | active cursor probe; ADR-008 |
| Outer rollback and savepoint | Recorded | Recorded | Recorded | transaction probe; ADR-008 |
| DDL transaction state | Implicit-commit evidence | Implicit-commit evidence | Transactional observation | DDL probe; ADR-008 |
| Session/connection identity | Recorded | Recorded | Not applicable | session probe |
| Immediate read after write | Same-session observation only | Same-session observation only | Same connection | read-after-write probe |
| Foreign keys | Engine schema policy | Engine schema policy | Enabled and read back | SQLite runtime probe |
| WAL and writer contention | Not applicable | Not applicable | File-backed WAL/busy fixture | SQLite file probe |
| Affinity, strict tables, limits | Not applicable | Not applicable | Runtime/build evidence | SQLite file/runtime probes |

The bootstrap SQLite runtime was 3.45.1 with `MAX_VARIABLE_NUMBER=250000`.
Foreign-key enablement/readback and a 5,000 ms busy timeout succeeded. A memory
database remained in `memory` journal mode after a WAL request; the file-backed
fixture entered WAL, showed connection-local foreign keys, rejected text in a
strict integer column, and reproduced writer serialization.

Run the complete control matrix with:

```bash
bash tools/database-probes/run-services.sh
```

An entry reading “Recorded” means the reproduction exists; it is not a claim
that all engines produce the same value. Certification links the report from
the deployment inventory and reviews every difference against the selected
policy.
