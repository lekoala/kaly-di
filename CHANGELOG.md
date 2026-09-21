# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Changed (composition contract)

Service definitions are additive; the only intentional replacement is `rebind()`.

```text
Before:
set() / bind() / merge() could silently replace service definitions.

Now:
service definitions are additive;
duplicate ownership fails fast;
rebind() is the only intentional replacement operation.
```

- `set()` and `bind()` now throw a `LogicException` when the id is already defined.
- `merge()` is additive and atomic: any service-id collision fails before any
  mutation, even when both sides declare the exact same value.
- `rebind($id, $value)` deliberately replaces an existing definition, and fails if
  the id does not exist. Parameters and callbacks keep their previous merge
  semantics.

See [docs/definitions.md](./docs/definitions.md) for details.
