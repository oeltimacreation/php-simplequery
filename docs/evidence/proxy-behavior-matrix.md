# Proxy behavior matrix

Status: exact single-backend smoke fixtures; deployment topology certification open.

Proxy output is compared with the MariaDB 11.8.8 direct control. The fixture
versions/configurations are ProxySQL 3.0.1 and MaxScale 23.02.17-2. Neither is a
new SQL dialect.

The 2026-07-17 bootstrap run completed all native/emulated and
buffered/unbuffered ProxySQL/MaxScale reports without a failed probe
observation. ProxySQL reported server version `8.0.11` while routing to the
MariaDB 11.8.8 fixture, directly confirming that server metadata is not a safe
MariaDB/MySQL dialect selector. MaxScale exposed the backend's MariaDB version.
Both retained changed-row, generated-ID, DDL-state, savepoint, and active-cursor
behavior matching the direct control in this single-backend topology. Each mode
also completed 32 unique explicitly closed prepares, retained one connection
identity through transaction/savepoint completion, and did not leak a user
session variable into a new logical PDO connection.

The Phase 2 public executor smoke also passed 13 checks through each proxy in
native-buffered mode, including prepared CRUD/batch execution, immediate IDs,
changed-row counts, decimal aggregates, early cursor close, user-session
continuity, transaction-required row locking, redacted SQL errors, and bounded
observer diagnostics.

The managed transaction smoke runs through both proxies in native-buffered
mode. It verifies callback ownership, generated savepoints, external
transaction rejection, observable manual/DDL state loss, cursor completion
guards, and clean connection replacement without treating either proxy as a
dialect or retry guarantee. All 15 checks passed through both proxy fixtures in
the 2026-07-17 run.

Both proxies also passed all eight synthetic migration checks, including
deferred raw reporting, caller-owned direct PDO transactions, joins,
diagnostics, and direct-PDO row parity. This validates the disposable
single-backend fixture path only; application topology rollout remains a
separate migration responsibility.

| Behavior | ProxySQL fixture | MaxScale fixture | Required deployment extension |
| --- | --- | --- | --- |
| Native binary prepares | All probe statements | All probe statements | Backend pooling/history and exact rules/settings |
| Emulated prepares | Full probe repeat | Full probe repeat | SQL parsing/routing rules |
| Buffered/unbuffered execution | Both modes | Both modes | Production PDO and pool policy |
| Generated ID | Compared with direct | Compared with direct | Same-session routing under load |
| Changed affected rows | Compared with direct | Compared with direct | `FOUND_ROWS` and session reset |
| SQLSTATE/errors | Compared with direct | Compared with direct | Backend loss/failover error mapping |
| Transaction pinning | `transaction_persistent=1` | Readwritesplit transaction | Production hostgroups/router settings |
| Savepoints | Probe nested savepoint | Probe nested savepoint | Production routing/session history |
| Session state | Connection identity recorded | Connection identity recorded | Multiplex/reset/history policy |
| Immediate read after write | Single-backend observation | Single-primary observation | Replica topology and causal/stickiness policy |
| DDL implicit commit | Compared with direct | Compared with direct | Query rules and session routing |
| Active cursor | Each buffer mode | Each buffer mode | Backend pool behavior under concurrency |
| Dynamic prepare cardinality | 32 unique, explicitly closed statements per mode | Same | Production statement/cache limits |
| Logical session isolation | New PDO checked for leaked user variable | Same | Pool reuse under production load |
| Replay/delayed retry | Never inferred | Fixture explicitly disables both | Exact deployed values and failover reproduction |
| Ambiguous failed write | No automatic retry | No automatic retry | Inject backend loss during statement and commit |

The fixture intentionally uses one backend so it is deterministic and suitable
for public CI. It cannot certify read replica lag, causal reads, failover,
backend replacement, or production query rules. Those are release/deployment
probes, and a failed write or commit remains ambiguous regardless of proxy.

`php tools/database-probes/ambiguous-write.php TARGET` provides an operator-controlled
in-flight write window. After transport/backend interruption and recovery, its
printed marker is reconciled through the direct MariaDB control. The script
records the client exception and server fact separately; it never labels a
failed write safe to replay. Deployment certification must use the real
failover mechanism because the public one-backend fixture cannot reproduce
production election or replica promotion.
