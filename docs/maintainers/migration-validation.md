# Historical migration validation

The repository retains anonymized migration evidence for historical context,
not an active analyzer or compatibility workflow. The durable records are the
[synthetic validation summary](../evidence/migration-validation.json), the
[consumer requirements review](../evidence/consumer-requirements-audit.md),
including its insert-return classification, and the
[migration performance control](../evidence/migration-benchmark.json).

They contain no application source, private paths, credentials, customer
identifiers, or runtime Pixie dependency. The original executable corpus and
source analyzer were development-only and are no longer maintained.

For a real migration, create application-owned characterization tests and
review these boundaries manually:

- explicit `Connection` construction and connection ownership;
- generated-ID versus affected-row insert behavior;
- raw SQL trust boundaries and positional bindings;
- dynamic identifier allowlists;
- transaction, cursor, lock, and direct-PDO ownership;
- object, associative, scalar, and cursor result shapes;
- batching, pagination, index-friendly ranges, and vendor SQL;
- exact direct-engine and proxy paths claimed by the application;
- static-analysis, exception, redaction, and rollback behavior.

No library command certifies that an external application has migrated. The
application owner must retain the private review report, rollout evidence,
rollback plan, and production observations.
