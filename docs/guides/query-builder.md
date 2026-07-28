# Query builder

## Lifecycle

`Connection::table()` creates a fresh builder. Clause methods mutate and return
the same object, which supports conditional query construction without forcing
callers to retain every fluent return value.

Terminal methods never change clause state. Repeated compilation is
deterministic while state is unchanged, `first()` does not retain its temporary
limit, and `count()` does not replace the projection or pagination.

Cloning a builder creates independent mutable state. A structured child query
is snapshotted when attached, and it must belong to the same `Connection` as
its parent.

## Sources and projection

```php
$query = $db
    ->table('users', 'u')
    ->select('u.id', 'u.email')
    ->distinct();
```

The implemented source/projection surface is:

```php
Connection::table(
    string|Identifier|QueryBuilder $source,
    ?string $alias = null,
): QueryBuilder
QueryBuilder::select(string|Identifier|RawExpression ...$columns): self
QueryBuilder::distinct(): self
QueryBuilder::as(string $alias): self
```

A builder source is snapshotted and requires an alias. Multiple structured
`FROM` sources are not supported; use joins or trusted raw SQL.

Without an explicit projection, the query selects `*`. Repeated `select()`
calls append in call order. Projection strings recognize `*` and qualified
wildcards such as `users.*`; normal strings are parsed only as qualified
identifiers, never as free-form expressions or `AS` aliases.

## Identifiers and aliases

```php
$column = Identifier::of('users.email')->as('email_address');
$literalDot = Identifier::fromSegments('column.with.dot');
$wildcard = Identifier::wildcard('users');
```

Each qualified segment is quoted independently. Empty segments and NUL bytes
are rejected, embedded dialect quote characters are escaped, and aliases are
explicit and case-preserving.

Quoting prevents syntax injection; it does not authorize a request-derived
table or column. Applications must allowlist dynamic identifiers.

## Predicates

```php
$query
    ->where('active', true)
    ->where('age', '>=', 18)
    ->orWhere(static function (ConditionGroup $group): void {
        $group
            ->whereNull('deleted_at')
            ->where('status', 'pending');
    });
```

Supported comparison operators are `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`,
`LIKE`, and `NOT LIKE`. Operator strings are case-insensitive and emitted in a
canonical form. Arbitrary request-derived operators are rejected.

The predicate family includes:

- two- and three-argument `where()`, `orWhere()`, `whereNot()`, and
  `orWhereNot()`;
- closure groups;
- `whereIn()` and `whereNotIn()` with iterables or subqueries;
- `whereBetween()`;
- null and not-null predicates;
- complete trusted `RawExpression` conditions.

`whereNot()` negates the complete comparison, raw condition, or group. It does
not guess an inverse operator.

### Nulls and empty lists

- `where('x', null)` becomes `x IS NULL`.
- `whereNot('x', null)` becomes `x IS NOT NULL`.
- equality/inequality against null normalizes consistently.
- ordering comparisons against null throw `InvalidQueryException`.
- `whereIn('x', [])` becomes constant false.
- `whereNotIn('x', [])` becomes constant true.
- an empty closure group throws rather than broadening a query.
- null inside a non-empty list retains normal SQL three-valued logic.

In particular, `whereIn('x', [null, $value])` can match `$value` but does not
match a null `x`; `whereNotIn()` with any null list member normally matches no
row because the predicate becomes unknown rather than true. A null-only `IN`
or `NOT IN` list likewise matches no row. Use explicit `whereNull()`/
`whereNotNull()` groups when null membership is intended.

## Joins

```php
$query->leftJoin('profiles', static function (JoinClause $join): void {
    $join
        ->on('profiles.user_id', '=', 'users.id')
        ->onValue('profiles.visible', '=', true);
});
```

Normal `on()` operands are identifiers. A comparison to a value must use
`onValue()` or the equivalent value-oriented join method. A complete trusted
raw condition may be used when structured joins cannot represent the SQL.

Inner and left joins are supported. Right joins are not supported.

## Grouping, ordering, and pagination

```php
$query
    ->groupBy('department_id')
    ->having('total', '>', 10)
    ->orderBy('department_id', SortDirection::Asc)
    ->limit(50)
    ->offset(100);
```

Repeated group, having, and order clauses append. Repeated limit or offset
calls replace the prior value. Negative pagination values are rejected, and an
offset without a limit throws during compilation.

## Subquery snapshots

```php
$activeRoles = $db->table('roles')->select('user_id');
$users = $db->table('users')->whereIn('id', $activeRoles);

$activeRoles->where('active', false);
```

The later mutation does not change `$users`. Bindings from the snapshot are
merged into the parent in exact SQL occurrence order.

## Count semantics

`count()` returns the number of logical result rows before top-level order,
pagination, and locking:

- filters and joins remain;
- grouped and distinct shapes retain their meaning through a derived table;
- grouped count returns the number of groups/result rows;
- the original builder is unchanged;
- `PDOStatement::rowCount()` is never used for `SELECT` counting.

## Locking reads

`forUpdate()`, `forShare()`, `noWait()`, and `skipLocked()` compile for
MariaDB and MySQL. Execution requires an active transaction and InnoDB for the
documented guarantees. SQLite rejects lock clauses.

Exactly one lock mode is allowed. `noWait()` and `skipLocked()` are mutually
exclusive and require a lock mode. Ambiguous grouped, distinct, aggregate, or
derived-only lock shapes are rejected.

## Structured write shapes

Insert terminals require an unaliased physical table with no read clauses.
Update and delete accept predicates but reject aliases, projection, distinct,
joins, grouping, having, ordering, pagination, and locks.

The builder does not prevent a full-table update or delete. Teams should apply
their own review or wrapper policy when that risk is unacceptable.

See [results and writes](results-and-writes.md) for terminal return contracts.
