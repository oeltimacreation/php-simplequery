# Compatibility evidence records

These public records distinguish an accepted design policy, a reproducible
fixture observation, and a verified deployment fact. Only the last two backed
by archived probe output may create a compatibility claim.

The repository foundation, contracts, audits, benchmark control, and
reproducible direct/proxy fixtures are complete. Deployment certification stays
open until operations supplies the real non-secret inventory and archived
reports required by the deployment record.

Each record includes:

1. primary source and access date;
2. exact tested runtime/version and non-secret configuration;
3. minimal synthetic reproduction;
4. direct/proxy result;
5. chosen policy and known weakness;
6. portability consequence;
7. automated test or probe;
8. owning ADR.

Files:

- [deployment inventory](deployment-inventory.md) and its
  [machine-readable data](deployment-inventory.json);
- [public fixture baseline](fixture-baseline.json);
- [research dossiers](research-dossiers.md);
- [direct behavior matrix](direct-behavior-matrix.md);
- [proxy behavior matrix](proxy-behavior-matrix.md);
- [consumer requirement audit](consumer-requirements-audit.md) and its
  [anonymized read-only baseline](consumer-audit-baseline.json);
- [insert return audit](insert-return-audit.md);
- [synthetic migration validation](migration-validation.json) and its
  [performance control](migration-benchmark.json);
- [`v0.1.0` performance and stability baseline](v0.1.0-performance-baseline.md)
  and its [machine-readable comparison data](v0.1.0-performance-baseline.json);
- [`0.2.0` associative hydration experiment](0.2-associative-hydration-experiment.md),
  including paired source-order and observer-profile decisions;
- [`0.2.0` Phase 3 hardening evidence](0.2-phase-3-hardening.md), including
  external types, branch/path coverage, behavior gaps, and engine versions;
- [`v0.2.0` development baseline](v0.2.0-development-baseline.md) and its
  [machine-readable source, query, coverage, and benchmark record](v0.2.0-development-baseline.json);
- [`v0.3.0` development baseline](v0.3.0-development-baseline.md) and its
  [machine-readable source, public-contract, query, coverage, test, and
  benchmark record](v0.3.0-development-baseline.json);
- [`0.4.0` duplication and complexity inventory](0.4-duplication-and-complexity-inventory.md)
  and its [machine-readable before-metrics record](0.4-duplication-and-complexity-inventory.json);
- [`0.4.0` contract freeze](0.4-contract-freeze.md), recording the zero-change
  pledge, the deferred feature backlog, and the compilation hot-path allocation
  targets;
- [`0.3.0` production-adoption baseline](0.3-production-adoption-baseline.md),
  containing only anonymized aggregate evidence and release-scope decisions;
- [`0.3.0` transaction and exception ergonomics](0.3-transaction-and-exception-ergonomics.md),
  classifying manual ownership, SQLite immediate-mode evidence, and the
  application-owned error-inspection boundary;
- [`0.3.0` architecture and quality audit](0.3-architecture-and-quality-audit.md),
  recording the public signature manifest, internal seam review, hotspot and
  extraction decisions, static-analysis fixture, style audit, and high-risk
  branch coverage;
- [`0.3.0` production-shaped performance evidence](0.3-production-performance.md),
  recording paired `v0.2.0` measurements, query plans, memory, and soak gates;
- [`0.3.0` release-candidate certification](0.3-release-candidate-certification.md),
  recording local quality/coverage, minimum/current direct and proxy probes,
  path-redacted consumer validation, security review, and the remaining real-
  deployment publication blocker;
- [`0.3.0` projection ergonomics spike](0.3-projection-ergonomics-spike.md),
  recording the decision to retain variadic `select()` without a list parser;
- [`0.4.0` performance and stability evidence](0.4-performance-and-stability.md),
  recording paired `v0.3.0` measurements, hot-path allocation removal, SQLite
  contention probes, and memory soak gates;
- [edge-feature usage verification](feature-usage-verification.md).

Generated reports belong in `tools/database-probes/results/`, are ignored by Git, and
must be attached to the relevant CI run or release evidence. They contain only
synthetic fixture information and are still reviewed for accidental secrets
before publication.

Validate the repository records with `composer verify`. Release/deployment
certification uses `php scripts/verify-repository.php --certify` and
intentionally fails while any real deployment record remains unverified.
