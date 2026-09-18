# FrankenPHP worker rehearsal

Synthetic, reproducible rehearsal of the `0.8.0` worker lifecycle contract under
FrankenPHP. It is maintainer tooling, not a library API or a production test.

`run.sh` starts the disposable MariaDB/MySQL fixtures from
`tools/database-probes/compose.yaml`, builds the pinned
`Dockerfile` image (FrankenPHP + PHP ZTS + `pdo_mysql`), starts classic and
worker-mode servers using the committed `Caddyfile.classic` and
`Caddyfile.worker` configurations (one worker thread matched to every request),
and drives deterministic scenarios through `drive.php`:
cache-only requests, warm reuse, session pinning, transactions, report session
restoration, query failure and recovery, failed connection construction, idle
replacement, and abrupt session loss. Worker mode can then run a bounded
mixed-workload soak with idle gaps and failure cycles.

```bash
# Short local rehearsal (default 60-second soak per engine).
bash tools/worker-rehearsal/run.sh

# Full gate rehearsal with a one-hour soak per engine.
REHEARSAL_SOAK_SECONDS=3600 bash tools/worker-rehearsal/run.sh

# Keep the database fixtures and containers for debugging.
REHEARSAL_KEEP_SERVICES=true bash tools/worker-rehearsal/run.sh

# Run two independent rehearsals concurrently (distinct containers/ports).
REHEARSAL_RUN_ID=mariadb REHEARSAL_ENGINES=mariadb REHEARSAL_PORT_BASE=18080 \
    REHEARSAL_KEEP_SERVICES=true REHEARSAL_SOAK_SECONDS=3600 bash tools/worker-rehearsal/run.sh &
REHEARSAL_RUN_ID=mysql REHEARSAL_ENGINES=mysql REHEARSAL_PORT_BASE=18090 \
    REHEARSAL_KEEP_SERVICES=true REHEARSAL_SOAK_SECONDS=3600 bash tools/worker-rehearsal/run.sh &
docker compose -f tools/database-probes/compose.yaml down --volumes --remove-orphans
```

Reports and version captures are written to
`tools/worker-rehearsal/results/<run-id>/`, which is ignored by Git (the run id
defaults to the shell PID and can be set with `REHEARSAL_RUN_ID`). Each report
records the tested FrankenPHP/PHP/PDO and
engine versions and the pass/fail outcome per assertion, plus soak latency and
memory samples. Budgets are defined by the driver: cache p95 at most 25 ms,
read/write/transaction p95 at most 250 ms, soak memory growth at most 32 MiB,
no request errors, no cleanup failures, and bounded connection creation across
idle and failure cycles.

The pinned base image digest and tested runtime versions are recorded in the
`0.8.0` worker rehearsal evidence. The rehearsal is deliberately separate from
the private consumer: it uses synthetic routes and a disposable schema so it
can be published without consumer configuration or data.
