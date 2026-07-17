# Architecture

Status: compiler architecture implemented; executor and transaction layers are
the accepted target architecture for `0.1.0`.

PHP SimpleQuery separates query construction, compilation, and execution while
keeping the replaceable surface deliberately small.

```text
Application
    |
    v
Connection (PDO, driver, transaction state, optional observer)
    | table()                         | query()
    v                                 v
Mutable QueryBuilder                  RawQuery
    | compile                         | execute
    +---------------+-----------------+
                    v
Internal dialect compiler
    typed state -> SQL + ordered typed bindings
                    |
                    v
Immutable CompiledQuery
                    |
                    v
Internal PDO executor
    prepare -> bind -> execute -> hydrate/return -> observe/translate
```

## Public responsibilities

- `Connection` owns one PDO and all state associated with it.
- `QueryBuilder` owns one mutable query definition.
- `CompiledQuery` is detached immutable SQL and concrete bindings.
- `RawQuery` defers trusted raw SQL execution until a terminal is called.
- `Cursor` owns an active statement until exhaustion or explicit close.
- `Identifier`, `RawExpression`, `Binding`, and enums make input domains clear.
- `QueryObserver` is the only public observation integration.

## Internal responsibilities

- typed AST/state nodes;
- MariaDB, MySQL, and SQLite compiler strategies;
- binding normalization and ordered composition;
- PDO preparation, execution, hydration, and exception translation;
- transaction depth, savepoint names, and unusable-state handling.

Internal types are not subclassing, plugin, or compatibility contracts. The
project does not publish compiler replacement interfaces, driver registries,
custom AST hooks, or middleware.

## Core invariants

1. Values are bindings; SQL fragments are code.
2. Every operation has an explicit connection owner.
3. One builder represents one query.
4. Terminal operations do not accumulate or reset clauses.
5. Parent and child bindings remain in exact SQL occurrence order.
6. Nested builders are snapshotted when attached.
7. Cross-connection structured composition is rejected.
8. Debug rendering never participates in execution.
9. Connection loss never triggers hidden replay.
10. A dialect claim requires compiler and live tests.

## Source layout

```text
src/
├── Connection.php
├── ConnectionOptions.php
├── QueryBuilder.php
├── ConditionGroup.php
├── JoinClause.php
├── RawQuery.php
├── Cursor.php
├── Binding.php
├── CompiledQuery.php
├── Driver.php
├── ParameterType.php
├── SortDirection.php
├── Expression/
├── Exception/
├── Observability/
├── Testing/
└── Internal/
    ├── Ast/
    ├── Compiler/
    ├── Executor/
    └── Transaction/
```

See the [architecture decision records](adr/README.md) for the reasons behind
these boundaries.
