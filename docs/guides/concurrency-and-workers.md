# Concurrency and workers

SimpleQuery is designed to remain low-overhead and isolated across independent
PHP requests, processes, and workers. It does not make one PDO connection safe
for concurrent use.

## Ownership unit

One execution unit owns its:

- `Connection` and PDO;
- mutable builders;
- managed transaction stack;
- active cursor;
- optional observer lifetime.

Do not share these objects between concurrent requests, fibers, coroutines, or
tasks. PDO is blocking and stateful.

## Connection factories

Long-running applications should inject a factory that creates a fresh
connection for an execution unit and discards it after uncertain failures:

```php
final class DatabaseFactory
{
    public function create(): Connection
    {
        return Connection::connect(
            driver: Driver::MariaDb,
            dsn: $this->dsn,
            username: $this->username,
            password: $this->password,
        );
    }
}
```

The library does not pool connections, reconnect transparently, or restore
session state. Runtime, framework, proxy, and infrastructure layers own those
concerns.

## Explicit lifecycle decisions

Run the [connection lifecycle example](../../examples/connection-lifecycle.php)
for explicit retirement and replacement using SQLite, without a network server.

`isClosed()` checks wrapper state. `isReusable()` checks whether local state
permits considering sequential reuse: no tracked cursor, managed transaction,
PDO-reported physical transaction, quarantine, or closed/compiler-only state.
It sends no health-check SQL. It cannot detect a dead idle socket, untracked PDO
statements, dirty session settings, or every manually issued transaction on
every PDO runtime. If PDO state inspection throws, the wrapper is quarantined
and a `TransactionStateException` exposes `operation=is_reusable` and
`connectionUnusable=true`. See [ADR-024](../adr/024-explicit-connection-lifecycle.md).

An application may retain an ordinary non-persistent connection across
sequential units only after verifying its own session/resource cleanup. Pin
one connection for the whole unit, including every lazily resolved model.
Do not resolve a replacement independently for each model or statement.
Fresh connections per unit remain the default recommendation.

At the boundary before new work, application policy may replace an idle
connection through its factory. Use monotonic elapsed time and the effective
server/proxy idle settings; a threshold reduces exposure but cannot guarantee
liveness. An explicit check can also fail just before the next statement.
SimpleQuery does not choose a threshold, ping automatically, or retry that
statement. Query observers do not cover direct PDO work, transaction controls,
or delayed cursor fetching, so successful-query timestamps are incomplete.

For a known-lost or unusable connection, evict it from the application holder
and call `discard()`. It irreversibly closes the wrapper without state
inspection or explicit transaction/cursor cleanup. It accepts abandoned active
work, but never reports successful transaction completion. Old models,
builders, and raw queries remain bound to the old wrapper; cursor advancement
fails before another fetch. Close outstanding cursors explicitly, preserving
the original failure if cleanup fails, and release all old artifacts.

Discard does not revoke escaped PDO/statement references or prove physical
disconnection/rollback. Never replace a connection to continue an unfinished
transaction or replay an uncertain write. Create and initialize a replacement
for a later unit; construction failure must leave the holder empty and must
not trigger an unbounded connection loop. HTTP retry decisions and write
reconciliation remain application-owned.

## Framework-free request recipe

The runnable [worker request lifecycle example](../../examples/worker-request-lifecycle.php)
implements an application-owned holder and request scope without a framework,
container, or new library API:

- handlers acquire connections lazily, so a cache-only request opens none;
- one role resolves to one pinned owner for the whole unit, including every
  lazily resolved model, nested transaction, and builder;
- the idle threshold is evaluated only when a new unit first acquires a role,
  using monotonic elapsed time, so a long-running unit never swaps its owner;
- the boundary closes tracked cursors, reuses a connection only when
  `isReusable()` still reports local eligibility, and discards everything else;
- bounded counters and an explicit `finally` path make recovery observable.

The holder owns connections per role; the unit owns its models, builders, and
cursors. A request may read through a `read` role and write through `primary`,
but neither role may be replaced while the unit is active.

```php
$database = new WorkerDatabase(
    factory: static fn (): Connection => Connection::connect(/* ... */),
    initialize: static function (Connection $connection): void {
        // Runs before a new connection is published to a unit.
        $connection->query('SET SESSION ...')->execute();
    },
    clock: static fn (): float => hrtime(true) / 1_000_000_000,
    idleThresholdSeconds: 60.0,
);

$response = $database->request(static function (RequestScope $scope): array {
    try {
        $job = (new JobModel($scope))->find($jobId);
        $scope->connection('primary')->transaction(/* ... */);

        return ['status' => 200, 'job' => $job];
    } catch (QueryExecutionException $failure) {
        $scope->evict($failure);

        return ['status' => 503, 'body' => 'database unavailable'];
    }
});
```

Do not store a `Connection`, builder, or cursor in a controller property,
model, static, or container singleton that survives the unit. Resolving a
replacement inside a unit would move old models and unfinished transactions to
a new owner; the recipe refuses it. New requests must construct their own
models and builders.

## Session initialization and restoration

Every replacement must be initialized and verified before it is published. A
factory does not inherit session settings from the retired connection, and a
half-initialized connection must not be cached as ready. Run the required
initialization again after any creation or restart failure.

Temporary settings, such as a raised report statement limit, belong in a
`finally` that restores the initialized value. If restoration fails, evict the
role and preserve the original failure:

```php
$scope->withTemporarySessionChange(
    change: static function (Connection $connection): void {
        $connection->query('SET SESSION max_statement_time = 5')->execute();
    },
    restore: static function (Connection $connection): void {
        $connection->query('SET SESSION max_statement_time = 0.1')->execute();
    },
    work: static function (Connection $connection): array {
        return (new ReportService($connection))->run();
    },
);
```

Test the sequence explicitly on one worker: a report request that changes
session state, then an ordinary request that observes the initialized value;
repeat with the report failing before and after restoration. The direct
[session-hygiene probe](../../tools/database-probes/session-hygiene.php)
verifies the same boundaries on disposable MariaDB and MySQL sessions.

Session commands are trusted SQL. Executed through `Connection::query()` they
are observed and validated by the normal executor path; executed through
`Connection::pdo()` they bypass compiler, observer, and error translation.
Prefer the builder path when the application wants session commands visible in
observation.

## Timeout and deadline distinctions

These boundaries fail differently and none of them is a portable deadline:

| Boundary | Typical input | Limit and caveat |
| --- | --- | --- |
| Connect or read timeout | PDO driver options, DSN, network/proxy settings | Construction failure is `ConnectionException` with `operation=connect`; no wrapper exists. |
| Server idle timeout | `wait_timeout` and `interactive_timeout` (session and global) | The server closes an idle socket; local wrapper state can still look reusable, and the code differs by engine (`2006`, `2013`, `4031`). |
| Statement limit | MariaDB `max_statement_time` (seconds, broad statement scope) or MySQL `max_execution_time` (milliseconds, read-only `SELECT`) | A `SELECT` limit does not bound writes, transaction control, or commit, and the timeout code differs (`1969` vs `3024`). |
| Transaction or lock timeout | `innodb_lock_wait_timeout`, engine lock-wait settings | Produces its own conflict evidence (`1205`, `1213`, SQLite `5`/`6`) and does not establish that replay is safe. |
| Transport or read timeout | Driver, proxy, and OS settings | May surface as connection loss or an interrupted read, not as a query-level limit. |

An idle threshold is a policy, not a liveness guarantee: the next statement can
still fail, and a preflight check can fail just before that statement. Do not
add a ping per query. Measure acquisition and initialization cost explicitly
when tuning an idle policy, and verify effective session values in the
deployment rather than assuming a documented default.

## Lifecycle counters and observation

Record bounded counters for connection creation, replacement, eviction,
failure, cleanup, cursor leaks, and recovery outcomes, with low-cardinality
role and reason labels. Keep credentials, bindings, SQL, and unbounded query
history out of the counters, and let the application decide whether and how to
log or export them.

The query observer does not report connection lifecycle events, direct PDO
statements, transaction controls, or delayed cursor fetches; successful-query
timestamps are incomplete session-activity evidence. The 0.8 lifecycle
decision (OBS-1) keeps these counters application-owned and adds no lifecycle
observer. See [observability](observability.md#connection-lifecycle-is-application-owned);
adding an observer later requires amending
[ADR-010](../adr/010-non-interfering-observation.md).

## FrankenPHP worker loop

The recipe maps onto a FrankenPHP worker script without a framework:

```php
<?php
// worker.php
require __DIR__ . '/vendor/autoload.php';

$database = new WorkerDatabase(/* ... */); // Boot once per worker.

$handler = static function () use ($database): void {
    $database->request(static function (RequestScope $scope): void {
        // Route and dispatch; resolve connections through $scope.
    });
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($handled = 0; !$maxRequests || $handled < $maxRequests; ++$handled) {
    if (!frankenphp_handle_request($handler)) {
        break;
    }

    gc_collect_cycles();
}

$database->shutdown();
```

FrankenPHP resets superglobals between requests; it does not reset services,
models, builders, cursors, or connection holders. Garbage collection is not
database cleanup. Classic request mode remains the control when qualifying
worker behavior, and any worker claim pins the PHP, PDO, FrankenPHP, and engine
versions with the tested source.

## Failure inspection and eviction

`ConnectionException` construction failures expose `operation=connect`,
`sqlState`, `driverCode`, `driver`, and `connectionLabel`. The raw
`PDOException`, driver message, and trace are not retained, so diagnose from
normalized evidence and application policy, never message text. `operation` is
`closed` for calls on a retired wrapper and `compiler_only` for a PDO-free
compiler connection, so lifecycle bugs do not look like a down database.

Query loss and unusable transaction state stay distinct decisions:

```php
use Oeltima\SimpleQuery\Exception\ConnectionException;
use Oeltima\SimpleQuery\Exception\QueryExecutionException;
use Oeltima\SimpleQuery\Exception\TransactionException;

try {
    $connection = $factory->create();
} catch (ConnectionException $failure) {
    // operation=connect: no wrapper exists. Log normalized evidence and let
    // application policy decide whether a later unit may try again.
}

try {
    $connection->table('jobs')->where('id', $jobId)->update(['state' => 'claimed']);
} catch (QueryExecutionException $failure) {
    // sqlState/driverCode/driver; the statement may have been dispatched.
    $holder->evict($role);
    $connection->discard();
} catch (TransactionException $failure) {
    if ($failure->connectionUnusable) {
        $holder->evict($role);
        $connection->discard();
    }
    // callbackFailure, controlFailure, recoveryFailure, and getPrevious()
    // retain the available evidence; cursor cleanup quarantine sets the same
    // flag even without a recognizable connection-loss code.
}
```

`connectionUnusable` is a lifecycle signal, not a retry signal. A quarantined
wrapper must be discarded even when the code does not match a known idle-loss
value. This recipe classifies evidence; the library provides no portable
classifier.

Eviction and retry eligibility are separate decisions:

| Evidence (engine-specific inputs) | Eviction | Replay eligibility |
| --- | --- | --- |
| Construction failure (`operation=connect`), any code | Keep the holder empty; no wrapper exists | No statement was dispatched; a later unit may retry through bounded application policy. |
| Authentication/configuration rejection (`1045`, `ConfigurationException`) | Discard; correct configuration first | Never replay the same rejected configuration. |
| Capacity exhaustion (`1040`) | Discard any wrapper | Not categorically permanent and not automatically retryable; apply a bounded application backoff without assuming work ran. |
| Transport loss or idle expiry (`2002`, `2003`, `2006`, `2013`, `4031`) | Discard and quarantine | The statement may have been dispatched; treat writes and commits as ambiguous and reconcile. |
| Lock timeout/deadlock (`1205`, `1213`; SQLite `5`, `6`) | Verify transaction state before reuse | Callback side effects need independent proof; a conflict code does not establish idempotency. |
| `TransactionException::connectionUnusable` (with or without a code) | Discard | Never replay the callback or statements; inspect the named failure fields. |
| Cursor cleanup quarantine | Discard | Do not continue advancing the cursor or reuse the session. |

## Worker cleanup

At the end of a job/request:

1. exhaust or close every cursor;
2. ensure no physical transaction remains active;
3. close or discard the connection;
4. discard builders and bounded observer history;
5. create a replacement after connection or transaction-state uncertainty.

Persistent PDO remains outside the supported profile. Sequential reuse of an
ordinary PDO object does not enable `PDO::ATTR_PERSISTENT`. Reuse additionally
requires restoring temporary session settings and clearing request-specific
observation history; `isReusable()` does not perform those tasks.

The [worker request recipe](#framework-free-request-recipe) makes these steps
executable, including cursor close before reuse and a shutdown path that never
opens a new connection. Worker recycling is an operational fallback for memory
growth, not a substitute for boundary cleanup.

## Cursors

Incremental iteration limits PHP-side memory but may occupy the connection.
Buffered MySQL-family queries can still consume client memory. Unbuffered
queries prevent another statement on that connection until the cursor closes.

Never cross a transaction boundary with a live tracked cursor.

## Async runtimes

SimpleQuery does not provide async I/O, Swoole-specific coroutine support, or
parallel execution on one PDO. An async application may still isolate blocking
database work in appropriate workers and give each operation its own
connection.

## Proxies

ProxySQL and MaxScale may pin, multiplex, replay, or reroute sessions according
to their configuration. Applications and operators must align transaction
stickiness, read-after-write, session-command, and retry settings with the
published compatibility fixture. The library never treats proxy routing as an
application-level transaction guarantee.
