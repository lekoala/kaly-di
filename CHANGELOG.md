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

### Changed (argument validation)

- **BC:** `Parameters::resolveParameters()` — and therefore `Injector::invoke()` and
  `Injector::make()` — now rejects invalid argument lists with an
  `InvalidArgumentException`, thrown unconditionally before any dependency is
  resolved: unknown named arguments (previously silently ignored), surplus
  positional arguments when there is no variadic parameter (previously dropped),
  the same parameter provided positionally and by name, and a positional argument
  following a named one.
- Mixed positional and named arguments (a positional prefix followed by named
  arguments, like a PHP call) are now supported and land on their parameter; a
  positional argument used to be silently dropped in that case.

### Changed (container construction)

- Creating a container locks the `Definitions` it receives (`lock()` is
  idempotent; `createContainer()` already locked them). Mutating definitions
  after a container was built from them now always fails with a
  `DefinitionException`.
- `Container::get()` wraps any error raised while checking existence (the `has()`
  check inside `get()`, e.g. a failing autoloader) in a `ContainerException` with
  the original error as `previous`, so `get()` only throws PSR-11 exceptions. A
  direct `has()` call is not wrapped and surfaces the error as-is.
- Failed resolutions leave no partial state: nothing is cached before configure()
  runs, and the cycle guard only clears the marker of its own call, so the whole
  factory + callback chain is safely replayed on the next `get()`. Factories and
  callbacks must be idempotent.

### Fixed

- Callbacks registered on an id remapped to an unrelated class
  (`set(A::class, B::class)` where `B` does not extend `A` and shares no
  interface with it) now run on the produced instance. Ids that are part of the
  instance hierarchy still run exactly once.

### Documentation

- Clarified the boundary: unconditional configuration invariants are always-on
  `DefinitionException`s, while checks that may autoload or reflect otherwise-unused
  code remain development assertions.
- Documented that a composition is validated by tests exercising real configurations
  and entry points, not by an ahead-of-time graph audit.
- Documented argument validation and the absence of general exception wrapping in
  the Injector ([docs/injector.md](./docs/injector.md)), definition locking at
  container construction ([docs/definitions.md](./docs/definitions.md)), and the
  `has()`-inside-`get()` wrapping plus failure-replay guarantees
  ([docs/architecture.md](./docs/architecture.md)).

See [docs/definitions.md](./docs/definitions.md) for details.
