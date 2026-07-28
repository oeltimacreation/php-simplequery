# Raw SQL and security

SimpleQuery separates four input domains:

1. identifiers, quoted by the dialect;
2. values, represented by placeholders and bindings;
3. structured expressions and subqueries;
4. trusted raw SQL supplied by the caller.

Mixing these domains is a security and correctness defect.

## Bind values

```php
$query->where('email', $untrustedEmail);
```

Values must never be concatenated into SQL. Automatic values are null,
booleans, integers, floats, strings, and backed enums. Arrays and arbitrary
objects are rejected outside structured operations such as `whereIn()`.

Boolean values normalize to integer `0`/`1`. Floats bind as
locale-independent strings. `DateTimeInterface` is rejected; callers must
choose a format, timezone, and database representation explicitly.

LOB streams and binary data use an explicit validated `Binding` and
`ParameterType`.

## Allowlist identifiers

```php
$allowedSorts = ['created_at', 'email'];

if (!in_array($requestedSort, $allowedSorts, true)) {
    throw new InvalidArgumentException('Unsupported sort field.');
}

$query->orderBy($requestedSort);
```

Identifier quoting prevents a value from becoming SQL syntax, but it does not
grant access to a table or column. Always allowlist request-derived identifiers,
directions, and application-level query choices.

Normal identifier strings do not parse free-form `AS`, functions, operators,
or sort directions.

## Raw expressions

```php
$query->select(
    $db->raw('LOWER(?) AS normalized', [$value])
);
```

`Connection::raw()` creates a final `RawExpression`. Its SQL is not parsed,
sanitized, or made portable. Bindings remain ordered and are inserted at the
raw node's exact position during compilation.

Use a raw expression only when the SQL text is trusted application code.
Request input belongs in a binding or an allowlisted identifier.

When only the left comparison operand needs raw SQL, keep the operator and
value in the structured API:

```php
$query->where($db->raw('LOWER(users.email)'), '=', $normalizedEmail);
```

This form binds `$normalizedEmail`; it does not sanitize or validate the raw
function expression. Use `whereColumn()` when both operands are identifiers.

## Raw queries

```php
$rows = $db
    ->query('SELECT * FROM users WHERE status = ?', ['active'])
    ->getAssociative();
```

`query()` returns a deferred `RawQuery`. Execution happens at `get()`,
`first()`, `execute()`, or iteration.

Bindings are a positional list. Named and mixed placeholders are not
supported. Plain values receive the same normalization as builder values.

Use result terminals for row-returning SQL and `execute()` for non-row-returning
SQL. `RawQuery::first()` does not rewrite caller SQL or append a limit; it
fetches one row and immediately closes the statement. Add `LIMIT 1` yourself
when server-side work matters.

## Placeholder limits

The library does not promise to tokenize arbitrary raw SQL. Question marks in
strings, comments, or vendor syntax are interpreted by PDO and the selected
prepare mode. Placeholder mismatch is reported by PDO unless a future real
tokenizer is introduced.

## Diagnostics

Canonical diagnostics are placeholder SQL, ordered binding types/count,
driver, duration, and success/failure metadata. Binding values are redacted by
default and are not interpolated into exception messages.

Any future `toDebugSql()` helper is approximate, sensitive, and never
executable. Debug interpolation must not participate in subquery composition or
execution.

## Application responsibilities

SimpleQuery cannot provide:

- authorization for tables, columns, or rows;
- safe handling of SQL assembled from untrusted fragments;
- least-privilege database accounts;
- TLS or credential rotation;
- protection from secrets written by an application logger;
- portability for trusted vendor-specific SQL.
