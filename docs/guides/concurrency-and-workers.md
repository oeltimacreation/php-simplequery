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
