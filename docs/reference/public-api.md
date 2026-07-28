# Public API contract

This is the signature index for the public surface. The linked topic guides
define overloads, mutation rules, validation, result shapes, and dialect
limits. A semantic change requires an ADR and synchronized fixture update.

## Connection and configuration

```php
Connection::fromPdo(
    PDO $pdo,
    Driver $driver,
    ?ConnectionOptions $options = null,
    ?QueryObserver $observer = null,
): Connection

Connection::connect(
    Driver $driver,
    string $dsn,
    #[SensitiveParameter] ?string $username = null,
    #[SensitiveParameter] ?string $password = null,
    array $pdoOptions = [],
    ?ConnectionOptions $connectionOptions = null,
    ?QueryObserver $observer = null,
): Connection

Connection::table(string|Identifier|QueryBuilder $source, ?string $alias = null): QueryBuilder
Connection::raw(string $trustedSql, array $bindings = []): RawExpression
Connection::query(string $trustedSql, array $bindings = []): RawQuery
Connection::transaction(Closure $callback): mixed
Connection::pdo(): PDO
Connection::close(): void
```

Everything above is implemented. `close()` is idempotent after success and
rejects active physical transactions or tracked cursors rather than silently
completing or truncating them.

The two raw-SQL binding parameters are PHPDoc `list<mixed>` contracts; keyed
binding maps are rejected at runtime.

`Driver` has exactly `MariaDb`, `MySql`, and `Sqlite`. `ConnectionOptions` is a
final readonly declaration with nullable prepare-emulation, buffering,
`FOUND_ROWS`, persistence, SQLite busy-timeout, and connection-label fields.
The executable construction cases are in
[`connection-construction.json`](../../tests/Fixtures/Contracts/connection-construction.json).

Construction performs no environment lookup or topology discovery. Broad PDO
driver mismatch, hard-invariant conflict, invalid/inapplicable options,
missing MySQL-family `utf8mb4`, failed SQLite foreign-key verification, or an
unsupported runtime throws `ConfigurationException`. Connection establishment
failure throws `ConnectionException`. Credentials are never included in
diagnostics.

## Immutable public values

| Type | Contract |
| --- | --- |
| `Driver` | Closed backed enum for MariaDB, MySQL, and SQLite. |
| `SortDirection` | Closed enum with `Asc` and `Desc`. |
| `ParameterType` | Closed PDO-independent binding type enum. |
| `Binding` | Final readonly normalized value and explicit parameter type. |
| `CompiledQuery` | Final readonly placeholder SQL and ordered `list<Binding>`. |
| `Identifier` | Final immutable qualified/wildcard identifier with optional alias. |
| `RawExpression` | Final immutable trusted SQL and ordered bindings. |
| `ConnectionOptions` | Final readonly supported execution declarations. |
| `QueryExecution` | Final readonly, redacted post-attempt observation. |

These types are library-owned values, not extension points. Internal AST,
compiler, executor, and transaction types are excluded from compatibility
promises.

`CompiledQuery` rejects non-list/non-`Binding` input and automatic parameter
types before exposing its state. Consumer-created `QueryExecution` values
likewise validate parameter-type listness/members, non-empty SQL, finite
non-negative duration, non-negative affected rows, and transaction depth.

## Compiler testing toolkit

`Testing\CompilerConnection::for(Driver)` creates a PDO-free connection for
detached dialect assertions. `Testing\CompiledQueryAssertions::assertMatches()`
checks SQL, ordered values, and optionally concrete parameter types.
`Testing\CompiledWriteQuery` exposes detached insert, multi-row insert, update,
and delete compilation for tests that must not execute. These utilities invoke
the same closed internal compilers as production builders; they are not
compiler extension points.

## Builder clauses

Clause methods mutate and return the same `QueryBuilder`. `Connection::table()`
always returns a fresh builder. Cloning produces independent state and a child
query is snapshotted when attached.

```php
select(string|Identifier|RawExpression ...$columns): self
distinct(): self
as(string $alias): self

where(...): self
orWhere(...): self
whereNot(...): self
orWhereNot(...): self
whereIn(...): self
orWhereIn(...): self
whereNotIn(...): self
orWhereNotIn(...): self
whereBetween(...): self
orWhereBetween(...): self
whereNull(...): self
orWhereNull(...): self
whereNotNull(...): self
orWhereNotNull(...): self

join(...): self
innerJoin(...): self
leftJoin(...): self
groupBy(string|Identifier|RawExpression ...$columns): self
having(...): self
orHaving(...): self
orderBy(string|Identifier|RawExpression $column, SortDirection|string $direction = 'ASC'): self
limit(int $limit): self
offset(int $offset): self

forUpdate(): self
forShare(): self
noWait(): self
skipLocked(): self
```

Predicate overloads accept a complete trusted raw condition, a typed
`Closure(ConditionGroup): mixed`, `(column, value)`, or `(column, operator,
value)`. Join closures infer `Closure(JoinClause): mixed`. The finite operator
set is `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, and `NOT LIKE`. Join
`on()` operands are identifiers; `onValue()` is the value-binding form.

Inner/left joins are supported on all three engines. Typed row locks are
MariaDB/MySQL-only, require an active transaction at execution, and reject
ambiguous query shapes. Right joins, unions, generic upserts, and DML returning
are not supported.

Invalid values or states throw `InvalidQueryException`. A valid concept that
the selected engine or structured API does not support throws
`UnsupportedFeatureException`. See [query builder](../guides/query-builder.md).

## Terminals

Terminals never mutate clause state.

| Terminal | Return contract |
| --- | --- |
| `compile()` | Detached `CompiledQuery`. |
| `get()` | `list<stdClass>`. |
| `first()` | `stdClass|null`. |
| `getAssociative()` | `list<array<string, mixed>>`. |
| `firstAssociative()` | `array<string, mixed>|null`. |
| `iterate()` | One-shot final `Cursor<stdClass>`. |
| `iterateAssociative()` | One-shot final `Cursor<array<string, mixed>>`. |
| `count()` | Range-checked non-negative `int`. |
| `sum()` / `average()` | Preserved `int|float|string|null`; rejects distinct/grouped/HAVING shapes. |
| `min()` / `max()` | Preserved driver scalar or `null`; rejects distinct/grouped/HAVING shapes. |
| `insert()` / `insertMany()` | Affected rows as `int`. |
| `insertGetId()` | Immediately captured generated ID as `string`. |
| `update()` / `delete()` | Affected rows as `int`. |

The aggregate scalar cases are executable data in
[`aggregate-scalars.json`](../../tests/Fixtures/Contracts/aggregate-scalars.json).
Write shape and result rules are detailed in
[results and writes](../guides/results-and-writes.md).

`RawQuery` exposes the same appropriate object/associative/cursor terminals
plus `execute(): int`; construction does not execute SQL. Raw SQL and bindings
remain immutable for that query.

## Transactions, cursors, and observation

Managed outer transactions own physical completion; nested managed calls use
savepoints. A pre-existing physical transaction is external and is never
adopted. Every `Throwable` enters rollback handling. Live tracked cursors
reject transaction/savepoint completion rather than being truncated. See
[transactions](../guides/transactions.md).

A cursor close failure quarantines its connection. A failed transaction begin
is reusable only after verified physical inactivity; uncertain nested
savepoint creation also quarantines the connection.

`QueryObserver::queryExecuted(QueryExecution $execution): void` is the only
observation integration. It is post-attempt, connection-scoped, immutable,
redacted, and non-interfering. Observer failures never change the database
outcome or trigger replay. There is no global event or mutable last-query
state. See [observability](../guides/observability.md).

## Exceptions

```text
SimpleQueryException
├── ConfigurationException
├── InvalidQueryException
├── UnsupportedFeatureException
├── ConnectionException
├── QueryExecutionException
├── NumericOverflowException
└── TransactionException
    ├── ExternalTransactionException
    └── TransactionStateException
```

Execution failures expose SQLSTATE, driver code when available, placeholder
SQL, driver/connection identity, and the previous `PDOException`, without
interpolated binding values. Transaction failures expose the attempted control
operation, managed depth, driver/connection label, callback/control/recovery
failures, and whether the connection is unusable. Domain exceptions retain
identity when rollback succeeds. Pixie's broad vendor-normalized constraint
subclass family is not part of the public contract.
