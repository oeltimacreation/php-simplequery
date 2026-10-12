# ADR-005: Ordered positional bindings

- Status: Accepted
- Date: 2026-07-17

## Context

Nested raw expressions and subqueries require deterministic binding
composition. Named placeholders add repeated-name and renaming complexity that
the initial supported use cases do not require.

## Decision

MariaDB, MySQL, and SQLite compilation uses generated positional `?`
placeholders and `list<Binding>` in exact SQL occurrence order. Compilation
normalizes automatic values into concrete parameter types before producing a
`CompiledQuery`.

Mixed named/positional and associative raw binding arrays are excluded from
`0.1.0`.

## Float normalization amendment (2026-10-10)

Finite automatic floats use `sprintf('%.17h', $value)` and retain a fractional
marker for whole values. Seventeen significant digits preserve the input PHP
double on round-trip, including subnormal values and signed zero. PHP's
[locale-independent `h` format](https://www.php.net/manual/en/function.sprintf.php)
is available below the PHP 8.2 floor and does not depend on `serialize_precision`.
The library does not change process-wide precision or locale settings.

This replaces JSON float formatting, whose text could silently round values
according to the host's precision setting. Decimal text can change even under
default settings; callers requiring exact decimal scale or formatting must bind
strings explicitly. This is not an exact-decimal arithmetic guarantee.

## Consequences

- Parent and child bindings append during one depth-first traversal.
- Repeated compilation yields identical SQL and binding order.
- Raw SQL placeholder syntax remains PDO/driver-sensitive.
- Exact binding-order fixtures are required for every nested/raw shape.
