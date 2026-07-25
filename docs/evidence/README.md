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
- [edge-feature usage verification](feature-usage-verification.md).

Generated reports belong in `tools/database-probes/results/`, are ignored by Git, and
must be attached to the relevant CI run or release evidence. They contain only
synthetic fixture information and are still reviewed for accidental secrets
before publication.

Validate the repository records with `composer verify`. Release/deployment
certification uses `php scripts/verify-repository.php --certify` and
intentionally fails while any real deployment record remains unverified.
