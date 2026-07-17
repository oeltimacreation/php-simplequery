# Transactions

Status: implemented `0.1.0` contract.

## Callback API

```php
$result = $db->transaction(
    static function (Connection $db): mixed {
        // Execute related statements.
        return $value;
    },
);
```

The callback result is returned. Any `Throwable` triggers rollback handling.
When rollback succeeds, application/domain exceptions are rethrown unchanged.

## Ownership

- depth zero begins and owns the physical PDO transaction;
- nested managed callbacks use generated savepoints;
- nested success releases its savepoint;
- nested failure rolls back to its savepoint and rethrows;
- only the outermost managed scope commits or rolls back PDO;
- savepoint names are internal and never accept caller input.

If PDO is already in a transaction while SimpleQuery's managed depth is zero,
`transaction()` throws `ExternalTransactionException`. It never adopts or
commits externally owned work.

Builder queries may execute inside an externally managed PDO transaction, but
transaction completion remains the caller's responsibility.

## Direct PDO use

`Connection::pdo()` shares physical transaction and session state. Calling
`beginTransaction()`, `commit()`, or `rollBack()` directly inside a managed
callback is unsupported. The manager detects observable state loss and throws
`TransactionStateException`.

Direct PDO statements bypass observers, compiler validation, and error
translation.

## Active cursors

Commit, rollback, savepoint release, and rollback to savepoint reject a live
tracked cursor. The manager never force-closes it and silently truncates caller
results. Exhaust or explicitly close every cursor before leaving a managed
scope.

If a successful callback reaches completion with a live cursor, the connection
state is considered unusable and must be discarded. When a live cursor blocks
rollback after a callback failure, both failures are retained in a
`TransactionException`.

## Failure behavior

- callback failure plus successful rollback: rethrow the callback failure;
- rollback failure: retain both failures in `TransactionException` and mark the
  connection unusable;
- commit failure: throw `TransactionException` and attempt rollback only when
  PDO still reports an active transaction;
- implicit commit or manual transaction-state loss: throw
  `TransactionStateException`;
- no callback retry, hidden reconnect, or automatic statement replay.

After the connection is marked unusable, every execution or managed
transaction attempt fails. Discard the wrapper and its PDO.

`TransactionException` records the control `operation`, `managedDepth`,
`driver`, optional `connectionLabel`, original `callbackFailure`, primary
`controlFailure`, optional recovery `recoveryFailure`, and
`connectionUnusable`. The previous-exception chain points to the most relevant
recovery, control, or callback failure, while the named fields preserve all
available evidence without including bindings or credentials.

## DDL

Schema-changing SQL is unsupported inside managed transactions. MariaDB and
MySQL can implicitly commit DDL and invalidate savepoints. The manager detects
state loss where PDO exposes it but does not parse arbitrary raw SQL in
advance.

## Locking reads

MariaDB/MySQL `forUpdate()` and `forShare()` execution requires an active
transaction. `noWait()` and `skipLocked()` are optional lock modifiers with
engine- and version-sensitive behavior. See [database support](database-support.md).

## Retry policy

SimpleQuery does not retry transactions. Applications that implement retry
must classify transient failures, ensure callback safety, bound attempts, and
handle ambiguous commit outcomes. Idempotency keys and reconciliation belong
at the application/data-model layer.
