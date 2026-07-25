# Deployment and fixture inventory

Status: fixture inventory complete; deployment certification intentionally open.

| Target | Reproducible fixture | Deployment fact required before certification |
| --- | --- | --- |
| MariaDB | `mariadb:11.8.2` / `mariadb:11.8.8` | Exact server patch, PHP/mysqlnd, SQL mode, charset/collation, timeouts, buffering, persistence. |
| MySQL | `mysql:8.0.11` / `mysql:8.0.46` | Exact deployed 8.0 patch/minimum, PHP/mysqlnd, SQL mode, charset/collation, timeouts, buffering. |
| SQLite | Runtime floor 3.39.2 | Every production/test linked runtime and relevant compile options. |
| ProxySQL | `proxysql/proxysql:3.0.1-debian` | Exact deployment version, query rules, hostgroups, transaction persistence, multiplex/session-variable policy, upgrade review. |
| MaxScale | `mariadb/maxscale:23.02.17-2` | Exact deployed `23.02.z`, readwritesplit/causal-read settings, replay, delayed retry, session history, failover policy. |

Fixture versions are exact regression inputs, not assertions about production
or a promise that an older deployment is supported. The single-backend proxy
fixtures reproduce protocol, prepare, session, transaction, and ambiguity
behavior but cannot certify a production read-replica topology.

The machine-readable source is
[`deployment-inventory.json`](deployment-inventory.json). Operations supplies
non-secret facts and links archived reports, then changes each deployment
status to `verified`. The release gate is executable:

```bash
php scripts/verify-repository.php --certify
```

The command must continue to fail until every deployment record is real. This
prevents the reported broad `23.02` MaxScale baseline, a floating MySQL 8
label, or a workstation SQLite build from silently becoming support policy.
