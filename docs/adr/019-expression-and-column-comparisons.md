# ADR-019: Expression and column comparisons

- Status: Accepted
- Date: 2026-07-28

## Context

Production migration evidence found complete raw predicates whose SQL text was
trusted but whose comparison value should still have been a separate binding.
For example, callers could express `LOWER(users.email) = ?` only by placing the
operator and placeholder inside a one-argument raw condition. The same review
found identifier-to-identifier predicates outside joins that should not need
raw SQL.

The library must relieve that pressure without parsing SQL-like strings,
building a general expression tree, or blurring the identifier/value boundary.
The accepted contract also has to preserve exact positional-binding order on
MariaDB, MySQL, and SQLite.

## Decision

`where()`, `orWhere()`, `whereNot()`, `orWhereNot()`, `having()`, and
`orHaving()` accept a `RawExpression` in the existing two-argument
expression/value and three-argument expression/operator/value shapes. The raw
SQL is trusted code; the comparison value becomes a binding. Bindings already
owned by the expression are emitted before the comparison binding because that
is their placeholder order in SQL.

The finite operator set remains `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`,
and `NOT LIKE`. Equality with `null` compiles to `IS NULL`; inequality with
`null` compiles to `IS NOT NULL`; ordering or pattern comparison with `null` is
rejected. Negated null conditions retain the established `whereNot()` behavior.

`whereColumn()` and `orWhereColumn()` explicitly compare two structured
identifiers. They are available on `QueryBuilder` and `ConditionGroup` through
the shared condition contract. Strings passed to these methods are always
parsed as qualified identifiers and are never values or SQL expressions.

Join conditions retain explicit operand roles:

- `on()`/`orOn()` compare identifier operands and may replace exactly one
  operand with a `RawExpression`;
- `onValue()`/`orOnValue()` and the value-oriented join `where()` may use a
  `RawExpression` on the left while the right operand remains a binding;
- plain strings in `on()` remain identifiers, while values use the value
  methods, so no string changes meaning;
- two raw operands are rejected; a caller needing that shape supplies one
  complete trusted one-argument raw join condition;
- null values in value-oriented joins remain rejected because join-null
  semantics were not required by the evidence and the existing behavior must
  remain stable.

The internal AST gains closed expression/value, expression/identifier, and
expression-null predicate nodes. No AST type becomes public. Raw SQL remains
caller-owned and may be engine-specific; the structured surrounding operator,
binding, identifier quoting, and binding order are portable across supported
dialects.

## Rejected alternatives

- A public expression-tree hierarchy would add a parallel language and an
  extension surface disproportionate to the demonstrated cases.
- Parsing functions, aliases, operators, or placeholders from strings would
  make trust and portability ambiguous.
- Treating a right-hand join string as sometimes an identifier and sometimes a
  value would make safe review impossible.
- Accepting two separate raw join operands would provide no meaningful safety
  over one complete trusted raw predicate.
- Adding `selectList()` was rejected after the projection spike: variadic
  `select()` plus `Identifier::as()` is already concise and preserves typed
  boundaries without parsing a list syntax.

## Consequences

- Common function-to-value predicates keep values out of trusted SQL text.
- Column comparisons are visibly distinct from value comparisons.
- One-argument raw conditions preserve their meaning.
- Raw expressions can still be non-portable or non-SARGable; callers own that
  choice and should prefer index-friendly structured ranges when possible.
- Compiler, SQLite execution, live direct-engine probes, documentation, and
  independent level-9 consumer analysis cover the accepted overloads.
