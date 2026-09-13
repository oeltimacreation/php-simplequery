# Compatibility evidence records

This directory keeps the maintained compatibility, migration, and release
records. It distinguishes an accepted design policy, a reproducible fixture
observation, and a verified deployment fact. Only the last two backed by
archived probe output may create a compatibility claim.

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

Maintained compatibility and migration records:

- [deployment inventory](deployment-inventory.md) and its
  [machine-readable data](deployment-inventory.json);
- [public fixture baseline](fixture-baseline.json);
- [research dossiers](research-dossiers.md);
- [direct behavior matrix](direct-behavior-matrix.md);
- [proxy behavior matrix](proxy-behavior-matrix.md);
- [consumer requirement audit](consumer-requirements-audit.md) and its
  [anonymized read-only baseline](consumer-audit-baseline.json);
- [synthetic migration validation](migration-validation.json) and its
  [performance control](migration-benchmark.json);

Current release evidence:

- [`0.6.0` Phase 0 baseline and scope freeze](0.6-phase-0-baseline-and-scope.md).
- [`0.6.0` Phase 1 attributable performance evidence](0.6-phase-1-attributable-performance.md).
- [`0.6.0` Phase 2 compiler and binding efficiency evidence](0.6-phase-2-compiler-and-binding-efficiency.md).
- [`0.6.0` Phase 3 execution, hydration, and resource efficiency evidence](0.6-phase-3-execution-hydration-and-resource-efficiency.md).
- [`0.6.0` Phase 4 code quality and maintainer efficiency evidence](0.6-phase-4-code-quality-and-maintainer-efficiency.md).
- [`0.6.0` Phase 5 user experience and documentation evidence](0.6-phase-5-user-experience-and-documentation.md).
- [`0.6.0` Phase 6 release certification evidence](0.6-phase-6-release-certification.md).

Active development evidence:

- [`0.7` development and local candidate review](0.7-development-review.md),
  including bounded performance decisions, mutation results, retained database
  qualification and local certification. Pending remote and publication gates
  remain in the [`0.7` development plan](../plans/0.7.md).

Retained decision evidence:

- [`0.5.0` maintainability and performance evidence](0.5-maintainability-and-performance.md),
  recording the accepted architecture, code-quality, and benchmark baseline
  inherited by `0.6.0`;
- [`0.2.0` associative hydration experiment](0.2-associative-hydration-experiment.md),
  including paired source-order and observer-profile decisions;
- [`0.3.0` transaction and exception ergonomics](0.3-transaction-and-exception-ergonomics.md),
  classifying manual ownership, SQLite immediate-mode evidence, and the
  application-owned error-inspection boundary;
- [`0.3.0` architecture and quality audit](0.3-architecture-and-quality-audit.md),
  recording the public signature manifest, internal seam review, hotspot and
  extraction decisions, static-analysis fixture, style audit, and high-risk
  branch coverage;
- [`0.3.0` projection ergonomics spike](0.3-projection-ergonomics-spike.md),
  recording the decision to retain variadic `select()` without a list parser;

Unlinked completed release-phase snapshots and retired-tool inventories are
deliberately not maintained as separate records here. Their accepted behavior
is represented by the current guides, reference pages, ADRs, and release
evidence above.

Generated reports belong in `tools/database-probes/results/`, are ignored by Git, and
must be attached to the relevant CI run or release evidence. They contain only
synthetic fixture information and are still reviewed for accidental secrets
before publication.

Validate the repository records with `composer verify`. Release/deployment
certification uses `php scripts/verify-repository.php --certify` and
intentionally fails while any real deployment record remains unverified.
