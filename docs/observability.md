# Observability

Status: implemented Phase 2 contract.

SimpleQuery provides one narrow optional post-attempt observer. It does not
provide mutable events, middleware, a global registry, or a logging dependency.

## Observer contract

```php
interface QueryObserver
{
    public function queryExecuted(QueryExecution $execution): void;
}
```

The observer is injected when constructing a `Connection` and remains fixed
for that wrapper's lifetime.

It receives one immutable notification for each builder or `RawQuery`
statement attempt owned by the internal executor, including prepare, bind, or
execute failure.

No notification is emitted for:

- detached compilation;
- connection-construction probes;
- statements executed through `Connection::pdo()`;
- PDO transaction-control or savepoint calls in `0.1.0`.

## Execution metadata

Metadata includes:

- placeholder SQL;
- binding count and parameter types;
- duration;
- success or failure;
- affected rows when meaningful;
- driver and optional non-secret connection label;
- managed transaction depth.

Binding values are redacted by default. Observers cannot modify SQL, bindings,
results, or transaction behavior.

## Failure isolation

Observer failure is caught and cannot replace a successful database outcome or
the original query/transaction failure. It never triggers a query retry.

Observer implementations must handle their own durable error reporting. Avoid
blocking network work in a hot-path observer unless the application explicitly
accepts that cost.

## Logging and metrics

Applications can implement `QueryObserver` to bridge into PSR-3, tracing, or a
metrics system. The core has no dependency on those packages and ships no
first-party PSR-3 bridge in `0.1.0`.

## Recording tests

`Testing\RecordingQueryObserver` retains a caller-selected bounded number of
immutable executions for application assertions. Values are absent from the
records, and `clear()` provides an explicit lifecycle boundary.

## No last-query state

The core does not retain mutable per-connection `lastQuery()` state. Use
detached compilation, structured exception evidence, or a bounded recording
observer.
