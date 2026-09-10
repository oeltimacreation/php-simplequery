# Architecture decision records

ADRs record decisions that constrain the public contract and implementation.
Accepted records remain historical; a later change supersedes rather than
silently rewriting the original decision.

| ADR | Decision | Status |
| --- | --- | --- |
| [001](001-mutable-public-builder.md) | Mutable public builder | Accepted |
| [002](002-fresh-builder-and-stable-terminals.md) | Fresh builder and stable terminals | Accepted |
| [003](003-compiler-executor-separation.md) | Compiler/executor separation | Accepted |
| [004](004-sql-input-boundaries.md) | Identifier/value/raw/subquery boundaries | Accepted |
| [005](005-ordered-positional-bindings.md) | Ordered positional bindings | Accepted |
| [006](006-results-and-write-returns.md) | Results and write returns | Accepted |
| [007](007-deferred-raw-query-execution.md) | Deferred raw-query execution | Accepted |
| [008](008-transaction-ownership-and-savepoints.md) | Transaction ownership and savepoints | Accepted |
| [009](009-exception-evidence-and-redaction.md) | Exception evidence and redaction | Accepted |
| [010](010-non-interfering-observation.md) | Non-interfering observation | Accepted |
| [011](011-supported-databases-and-proxies.md) | Supported databases and proxies | Accepted |
| [012](012-direct-pixie-migration.md) | Direct Pixie migration | Accepted |
| [013](013-closed-extension-boundary.md) | Closed extension boundary | Accepted |
| [014](014-day-zero-tests-and-documentation.md) | Day-zero tests and documentation | Accepted |
| [015](015-modern-php-82.md) | Modern PHP 8.2 language policy | Accepted |
| [016](016-maintainer-led-scope-control.md) | Maintainer-led scope control | Accepted |
| [017](017-zerover-and-support-floors.md) | ZeroVer and support floors | Accepted |
| [018](018-evidence-gated-compatibility.md) | Evidence-gated compatibility | Accepted |
| [019](019-expression-and-column-comparisons.md) | Expression and column comparisons | Accepted |
| [020](020-sqlite-immediate-transaction-mode.md) | Reject managed SQLite transaction modes | Accepted |
| [021](021-conditional-builder-and-pagination.md) | Conditional builder and pagination helpers | Accepted |

| [022](022-malformed-condition-arguments.md) | Reject condition argument holes | Accepted |

## Format

Each record includes status, date, context, decision, and consequences. New
records should use a zero-padded sequence and a stable descriptive filename.
