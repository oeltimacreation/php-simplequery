# Results and writes

## Object results

Object hydration is the default:

```php
/** @var list<stdClass> $rows */
$rows = $query->get();

/** @var stdClass|null $row */
$row = $query->first();
```

Rows are writable `stdClass` objects. `first()` returns `null` when no row is
available and applies a temporary effective limit of one without changing the
builder.

## Associative results

```php
/** @var list<array<string, mixed>> $rows */
$rows = $query->getAssociative();

/** @var array<string, mixed>|null $row */
$row = $query->firstAssociative();
```

Fetch shape is selected by the terminal; there is no mutable global fetch
mode. Arbitrary class hydration and `PDO::FETCH_CLASS` are excluded.

PDO collapses duplicate result-column names before SimpleQuery receives the
row; the last value is therefore retained for both object and associative
hydration. Numeric column names are valid writable properties on object rows.
PHP converts numeric-string array keys to integers, so associative terminals
reject those rows to preserve their `array<string, mixed>` contract. Alias
columns to unique, non-numeric names when associative hydration is required.

## Iteration

```php
$cursor = $query->iterateAssociative();

try {
    foreach ($cursor as $row) {
        // Process one PHP row at a time.
    }
} finally {
    $cursor->close();
}
```

Static analysis exposes `Cursor<stdClass>` from `iterate()` and
`Cursor<array<string, mixed>>` from `iterateAssociative()`. `Cursor` is final,
one-shot, and non-rewindable. It closes its PDO statement on
exhaustion, explicit idempotent `close()`, or generator cleanup. Destructor
cleanup is only a non-throwing fallback. If PDO returns `false` or throws while
closing the physical cursor, the connection is quarantined because its
statement state cannot be proven reusable. Explicit close or exhaustion
reports that cleanup failure unless row fetching or validation already failed;
in a dual failure, the original fetch/result failure remains authoritative.

Buffered MySQL-family PDO may still buffer server results. An unbuffered cursor
occupies its connection. Commit, rollback, savepoint release, and rollback to a
savepoint reject a live tracked cursor instead of silently truncating it.

## Write terminals

```php
$affected = $db->table('users')->insert([
    'email' => 'person@example.test',
    'active' => true,
]);

$id = $db->table('users')->insertGetId([
    'email' => 'person@example.test',
]);

$updated = $db
    ->table('users')
    ->where('id', 42)
    ->update(['active' => false]);

$deleted = $db
    ->table('users')
    ->where('id', 42)
    ->delete();
```

Return contracts are intentionally single-purpose:

| Terminal | Return |
| --- | --- |
| `insert()` | affected rows as `int` |
| `insertGetId()` | generated ID as `string` |
| `insertMany()` | affected rows as `int` |
| `update()` | affected rows as `int` |
| `delete()` | affected rows as `int` |

Affected rows are changed rows in the default MariaDB/MySQL profile, not a
portable matched-row count. `insertGetId()` captures PDO's generated ID
immediately on the same connection and throws `QueryExecutionException` when
that terminal cannot obtain one. An allocated ID is not proof of commit.

## Batch insert

`insertMany()` compiles one multi-row statement. It requires:

- a non-empty list of non-empty rows;
- identical columns in every row;
- deterministic first-row column order;
- no missing or extra columns.

The library does not chunk automatically because chunking changes atomicity
and failure behavior. Applications choose chunk size and transaction scope.
Generated batch IDs are never inferred.

## Aggregates

```php
$count = $query->count();
$sum = $query->sum('amount');
$average = $query->average('amount');
$minimum = $query->min('amount');
$maximum = $query->max('amount');
```

`count()` validates a non-negative decimal result and returns a PHP `int`.
Values above `PHP_INT_MAX` throw `NumericOverflowException`.

`sum()` and `average()` preserve the driver scalar as
`int|float|string|null`, including exact decimal strings. `min()` and `max()`
preserve the driver scalar/null without arbitrary coercion.

`sum()`, `average()`, `min()`, and `max()` require a single scalar query shape.
They reject builders containing `distinct()`, `groupBy()`, or `having()` with
`UnsupportedFeatureException`; `distinct()` on the builder does not mean
`SUM(DISTINCT column)`. Select an explicit aggregate expression and fetch rows
when grouped aggregate results are required. `count()` continues to support
distinct, grouped, and `HAVING` logical result shapes.

## Unsupported write features

Generic upsert, `insertIgnore()`, `replace()`, DML `RETURNING`, joined writes,
ordered/limited writes, and unions are not supported by the structured API.
Engine-specific raw SQL remains available when an application deliberately
accepts those semantics.
