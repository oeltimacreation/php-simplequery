# Security review checklist

Use this checklist for changes involving identifiers, expressions, bindings,
compilers, raw SQL, execution, diagnostics, or transactions.

## Input domains

- [ ] Identifier, value, structured expression, and raw SQL paths remain
      distinct.
- [ ] Request-derived identifiers/directions/operators require allowlists.
- [ ] Values cannot become SQL through interpolation or implicit stringification.
- [ ] Raw SQL remains visibly trusted and never claims sanitization.
- [ ] Child/raw bindings retain exact SQL occurrence order.

## Binding and execution

- [ ] Every automatic value maps to a documented concrete parameter type.
- [ ] LOB/binary explicit types are validated per supported driver.
- [ ] Placeholder SQL and bindings remain canonical; debug SQL is never
      executable.
- [ ] Generated IDs are captured immediately on the same connection.
- [ ] Connection loss and ambiguous writes are never replayed automatically.

## Diagnostics

- [ ] Exceptions do not interpolate binding values.
- [ ] Observer and recording output redact values by default.
- [ ] DSNs, credentials, hostnames, and private configuration are excluded.
- [ ] Observer failures cannot change database outcomes.
- [ ] Long-running recording has an explicit bound/lifetime.

## Transactions and resources

- [ ] Externally owned transactions cannot be committed or rolled back.
- [ ] All `Throwable` paths perform the documented rollback handling.
- [ ] Savepoint names are generated internally.
- [ ] Live cursors cannot cross completion boundaries silently.
- [ ] Unusable/closed connections reject later execution.
- [ ] DDL implicit-commit limitations remain documented.

## Tests and review

- [ ] Golden compiler and exact binding tests cover the change.
- [ ] Every supported engine has a live test where behavior is engine-sensitive.
- [ ] Proxy behavior is tested when session/routing semantics are affected.
- [ ] Synthetic fixtures contain no private or production data.
- [ ] Documentation and ADRs reflect the accepted behavior.
