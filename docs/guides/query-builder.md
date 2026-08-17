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

## Conditional construction

`when()` and `unless()` run one mutation callback when a value is truthy or
falsey respectively. The callback receives the current builder, and both
methods return that same builder. A callback return value is ignored.

```php
$query
    ->when($status !== null, static function (QueryBuilder $query) use ($status): void {
        $query->where('status', $status);
    })
    ->unless($includeDeleted, static function (QueryBuilder $query): void {
        $query->whereNull('deleted_at');
    });
```

The value uses PHP boolean coercion. `null`, `false`, `0`, an empty string,
and an empty array are falsey; objects are truthy. The callback is not invoked
for the other branch, and callback exceptions propagate unchanged.

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

Keep projection lists structured even when they are long:

```php
$query->select(
    'u.id',
    Identifier::of('u.email')->as('contact'),
    Identifier::wildcard('profiles'),
    $db->raw('LOWER(u.email) AS normalized_email'),
);
```

There is no comma-list parser or `selectList()` method. Only the function call
above needs trusted raw SQL; identifiers, wildcards, and aliases retain their
typed meaning. The [projection ergonomics spike](../evidence/0.3-projection-ergonomics-spike.md)
records why the existing variadic API was retained.

Use a trusted raw projection only for syntax that cannot be represented as an
identifier, wildcard, or alias. Keep values in its ordered binding list:

```php
$query->select(
    'orders.id',
    Identifier::of('orders.customer_id')->as('customer'),
    $db->raw('orders.total * ? AS converted_total', [$exchangeRate]),
);
```

The SQL text remains application-authored code. A raw expression is not parsed,
escaped, or made portable by the builder.

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
    ->where($db->raw('LOWER(email)'), '=', $normalizedEmail)
    ->whereColumn('events.owner_id', '=', 'users.id')
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

A trusted expression can be the left side of a value comparison without
placing the operator or value placeholder inside raw SQL:

```php
$query->where(
    $db->raw('COALESCE(LOWER(users.email), ?)', ['']),
    '=',
    $normalizedEmail,
);
```

The expression's binding (`''`) occurs first, followed by the normalized-email
binding. Two-argument expression/value comparisons imply `=`. These overloads
also work in condition groups and `HAVING`. The expression is still trusted
application code and is not parsed or made portable by the builder.

Use `whereColumn()`/`orWhereColumn()` when both operands are identifiers.
Strings in those methods are always identifiers, never values or expressions.

`whereNot()` negates the complete comparison, raw condition, or group. It does
not guess an inverse operator.

### Date and time ranges

Prefer a half-open range over wrapping an indexed column in a function. Compute
the boundaries in application code and bind both values:

```php
$query
    ->where('events.created_at', '>=', $startUtc)
    ->where('events.created_at', '<', $nextDayUtc);
```

This avoids double-counting a boundary and lets supported engines consider an
ordinary index on `created_at`. A vendor function such as `DATE(...)` requires
a trusted raw expression and may need a matching functional index. Confirm
important shapes with production-like data and the selected engine's query-plan
tooling; the builder does not control indexes.

### Nulls and empty lists

- `where('x', null)` becomes `x IS NULL`.
- `whereNot('x', null)` becomes `x IS NOT NULL`.
- equality/inequality against null normalizes consistently.
- ordering comparisons against null throw `InvalidQueryException`.
- expression equality/inequality against null uses the same `IS NULL`/`IS NOT
  NULL` policy after emitting any bindings inside the expression.
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

Exactly one `on()` operand may instead be an explicit `RawExpression`:

```php
$query->join('events', static function (JoinClause $join) use ($db): void {
    $join
        ->on($db->raw('events.owner_id + ?', [0]), '=', Identifier::of('users.id'))
        ->onValue($db->raw('LOWER(events.kind)'), '=', $normalizedKind);
});
```

Plain strings passed to `on()` remain identifiers. Two raw operands are
rejected; use one complete trusted raw condition for an inherently raw
expression-to-expression join. Join value methods continue to reject null.

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

`forPage()` provides strict 1-based pagination by setting both values:

```php
$page = $db->table('users')->orderBy('id')->forPage(3, 25);
```

The example emits `LIMIT 25 OFFSET 50`. Page and page-size values must both be
positive, and an offset outside the supported integer range is rejected before
the builder changes. `forPage()` does not add ordering or execute a count
query; callers are responsible for deterministic ordering when page boundaries
matter. Later `limit()` or `offset()` calls continue to replace its values.

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

To count distinct non-null values, project only that identifier, apply
`distinct()`, and call `count()`:

```php
$customerCount = $db
    ->table('orders')
    ->select('customer_id')
    ->distinct()
    ->whereNotNull('customer_id')
    ->count();
```

The explicit null predicate documents whether null is part of the logical
result. For a grouped report, build the intended grouped query and call
`count()` to count its result rows. Do not place `COUNT(DISTINCT ...)` inside a
raw scalar projection merely to recover the structured logical-count contract.

## Deliberate raw boundaries

Use structured identifiers, comparisons, joins, ranges, grouping, and ordering
whenever they express the query. A complete `RawExpression` is appropriate for
vendor predicates or expression-to-expression SQL that the finite API does not
model; `Connection::query()` is the escape path when the whole statement is
inherently raw.

At every raw boundary:

- keep SQL text fixed and application-authored;
- keep runtime values in ordered positional bindings;
- allowlist request-derived identifiers before constructing `Identifier`;
- test exact placeholder SQL and binding order;
- run the query on every engine or proxy for which the application claims
  support.

See [raw SQL and security](raw-sql-and-security.md) for the complete trust
model.

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
