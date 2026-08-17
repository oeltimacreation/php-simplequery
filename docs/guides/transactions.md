# Transactions

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

Keep every statement that belongs to the atomic unit inside the callback, and
return only data that remains valid after commit:

```php
$orderId = $db->transaction(
    static function (Connection $connection) use ($order, $lines): string {
        $id = $connection->table('orders')->insertGetId($order);
        foreach ($lines as $line) {
            $connection->table('order_lines')->insert($line + ['order_id' => $id]);
        }

        return $id;
    },
);
```

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

Choose one owner for each physical transaction. If a framework or application
owns PDO completion, use builder terminals inside that scope but do not call
`Connection::transaction()`:

```php
$pdo = $db->pdo();
$pdo->beginTransaction();
try {
    $db->table('jobs')->where('id', $jobId)->update(['state' => 'claimed']);
    $pdo->commit();
} catch (Throwable $failure) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $failure;
}
```

That scope is entirely application-owned; SimpleQuery will not complete it.

## Direct PDO use

`Connection::pdo()` shares physical transaction and session state. Calling
`beginTransaction()`, `commit()`, or `rollBack()` directly inside a managed
callback is unsupported. The manager detects observable state loss and throws
`TransactionStateException`.

Direct PDO statements bypass observers, compiler validation, and error
translation.

SQLite immediate transactions are not a managed mode. Applications that need
writer intent at begin may use direct PDO with fixed trusted `BEGIN IMMEDIATE`,
`COMMIT`, and `ROLLBACK` control SQL, but they own the entire scope and must not
call `transaction()` inside it. PHP 8.2/8.3 PDO SQLite can execute manual begin
while `inTransaction()` remains false, so SimpleQuery cannot detect or complete
that external work on every supported runtime. Test the exact deployment and
prefer a dedicated helper that always completes the scope.

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
- begin failure: inspect physical state, roll back an active transaction, and
  permit reuse only after inactivity is verified;
- nested savepoint creation failure: treat dispatch as ambiguous and
  quarantine the connection because savepoint state cannot be proven;
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
engine- and version-sensitive behavior. See
[database support](../reference/database-support.md).

Acquire MySQL-family row locks inside an ordinary managed transaction and
finish reading any cursor before the callback returns:

```php
$job = $db->transaction(
    static function (Connection $connection): ?array {
        $job = $connection
            ->table('jobs')
            ->where('state', 'ready')
            ->forUpdate()
            ->skipLocked()
            ->firstAssociative();

        if ($job !== null) {
            $connection->table('jobs')->where('id', $job['id'])->update(['state' => 'claimed']);
        }

        return $job;
    },
);
```

`NOWAIT`, `SKIP LOCKED`, deadlock selection, and lock-timeout behavior are
engine-, version-, topology-, and workload-sensitive. Preserve the exception's
SQLSTATE and driver code as evidence, then decide in the application whether
the operation can be abandoned, reconciled, or deliberately retried.

## Retry policy

SimpleQuery does not retry transactions. Applications that implement retry
must classify transient failures, ensure callback safety, bound attempts, and
handle ambiguous commit outcomes. Idempotency keys and reconciliation belong
at the application/data-model layer.

Applications can inspect `QueryExecutionException::driver`, `sqlState`, and
`driverCode` to distinguish their own known deployment cases. Always normalize
the driver code before comparing because PDO may expose it as an integer or a
string, and branch on `Driver` before interpreting it. Do not treat `HY000` by
itself as a lock conflict: it also covers transport and general failures.

A typical application-owned lock-conflict check uses MySQL-family codes `1205`
and `1213`, or SQLite SQLSTATE `HY000` with codes `5` and `6`. Those values are
diagnostic inputs, not proof that a callback is safe to replay. See the
[transaction and exception evidence](../evidence/0.3-transaction-and-exception-ergonomics.md)
for the complete recipe and limits.

Before any retry, the application must separately prove bounded attempts,
idempotent or compensatable effects, safe generated-ID behavior, and a policy
for ambiguous commit outcomes. SimpleQuery deliberately provides no retry flag
or automatic callback replay.
