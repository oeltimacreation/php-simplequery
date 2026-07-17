# Concurrency and workers

Status: target `0.1.0` contract.

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

## Worker cleanup

At the end of a job/request:

1. exhaust or close every cursor;
2. ensure no physical transaction remains active;
3. close or discard the connection;
4. discard builders and bounded observer history;
5. create a replacement after connection or transaction-state uncertainty.

Persistent PDO is outside the default support profile. Applications opting in
must guarantee transaction, cursor, prepared-statement, and session cleanup
before reuse.

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
