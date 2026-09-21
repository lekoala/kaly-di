# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Added

- `DefinitionException`: invalid configuration now always fails, regardless of
  `zend.assertions`. It extends `LogicException` and implements the PSR-11
  `ContainerExceptionInterface`, since it can surface from `Container::get()`.
- `merge()` now returns `$this`, so it chains like every other mutator.
- `alias($alias, $target)`: an explicit alias whose `get()` delegates to its
  target. The target is configured once (unlike a factory returning another entry,
  which runs its callbacks again). Aliases cannot carry parameters or callbacks,
  and alias cycles are rejected.
- `set()`, `bind()` and `rebind()` accept an optional `source:` provenance label
  (diagnostics only). `rebind()` preserves the initial source and records the
  replacement; duplicates and merge collisions report both. Sources never trigger
  autoload or reflection.
- `UnresolvableParameterException` now exposes `getObjectId()` and a structured
  `getResolutionPath()`. Nested resolution messages name the immediate parameter
  (`cannot resolve parameter: leaf`) and the outermost exception carries the full
  path (`Root::$middle -> Middle::$leaf -> Leaf::$apiKey`).

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

- `set()` and `bind()` now throw a `DefinitionException` when the id is already defined.
- `merge()` is additive and atomic: any service-id collision fails before any
  mutation, even when both sides declare the exact same value.
- `rebind($id, $value)` deliberately replaces an existing definition, and fails if
  the id does not exist. Parameters and callbacks keep their previous merge
  semantics.
- `rebind()` accepts an optional `expected` precondition, turning a replacement
  into a compare-and-swap: the id must still be defined exactly (strict identity)
  as assumed, otherwise the call fails. This guards tests, demos and variants
  against stale overrides.
- A factory returning anything other than an object or a class-string now throws a
  `DefinitionException` (previously an `assert()`, so it could pass silently in
  production).
- Hardening: a configured parameter whose name does not exist on the constructor is
  now rejected with a `DefinitionException` at resolution time, instead of being
  silently ignored. The check runs before parameter closures are executed and lists
  the unknown and available names.

### Documentation

- Clarified the boundary: unconditional configuration invariants are always-on
  `DefinitionException`s, while checks that may autoload or reflect otherwise-unused
  code remain development assertions.
- Documented that a composition is validated by tests exercising real configurations
  and entry points, not by an ahead-of-time graph audit.

See [docs/definitions.md](./docs/definitions.md) for details.
