# Changelog

All notable changes to this project are documented in this file.

## [0.5.0]

### Fixed

- Configured parameters of a concrete class are now resolved against its
  declared name. A binding (`bind()`), a `set()` entry or a factory returning a
  class spelled with a leading backslash or a different case no longer silently
  falls back to the constructor default.
- A definition whose target spells the concrete class differently — a
  `class_alias()` target, a case variant of a short class name — no longer
  silently drops parameters configured under that spelling. The parameter
  lookup covers the declared class name, the binding target as written and the
  service id, in that order of precedence.
- `Container` and `Injector` calls no longer pass `null` for trailing optional
  parameters that PHP declares without an exposed default (e.g.
  `array_keys($data)`). The arguments are omitted like a direct call, while an
  explicitly provided `null` is preserved.
- Explicit arguments and configured constructor parameters now have to match
  their declared type even when `zend.assertions=-1`. Previously only a
  development assertion caught the mismatch and Reflection could coerce it in
  production (`'false'` for a `bool` parameter became `true`). The rejection is
  an `InvalidArgumentException`, wrapped in a `ContainerException` by
  `Container::get()`.
- `get()` normalizes errors raised while checking an alias id, for instance an
  autoloader throwing during the alias type check. `has()` and `get()` therefore
  keep their documented contracts, and the original error remains available as
  `previous`.
- `get()` wraps a `NotFoundExceptionInterface` raised while resolving an alias —
  for instance an autoloader looking up one of its own dependencies during the
  type check — in a `ContainerException` with the original as `previous`. An
  alias that `has()` reports as available is therefore never returned as
  missing.
- `Injector::invoke()` and `Injector::make()` reject callables/constructors with a
  by-reference parameter with an `InvalidArgumentException` before resolution.
  Reflection cannot preserve the caller's reference, so passing a value used to
  emit a warning and leave the caller's variable unchanged.
- A parameter typed in another case (`\psr\container\containerinterface`) now
  receives the current container like the declared spelling, instead of failing
  with an `UnresolvableParameterException`.

### Performance

- `has()` returns immediately for an already cached instance; parameter
  normalization is skipped when no arguments are provided; the configured
  parameter check is skipped when a service has no configured parameters; and
  `callbacksForClass()` returns early when no callbacks are registered.
- Only the canonical id owns a cached shared instance. Requesting the same class
  through many spellings (`Foo`, `foo`, `\FOO`) no longer adds one cache entry
  per spelling; explicitly declared alias ids keep their own entry.
- Alias graph validation is linear: nodes already proven acyclic are marked
  once during a merge, instead of walking the successor chain again for every
  alias. A 1000-alias chain now merges in constant time per alias.

### Documentation

- Documented that `$callable` (for `invoke()`) and `$class` (for `make()`) are
  owned by the `Injector` signature: a target parameter with that name must be
  passed positionally. Documented the by-reference rejection.
- Documented the `stdClass` exception to the "no reserved ids" rule and that
  `Parameters` is internal.
- Classified the `Definitions` read methods: `has()`, `get()`, `getAliases()`,
  `parametersFor()`, `callbacksFor()` and `sourceFor()` are the read API, while
  the raw storage accessors and `expand()` are marked `@internal`.
- Documented the argument validation timing through `Container::get()`: unknown
  configured parameter names are rejected before configured parameter closures
  run. Type mismatches and by-reference parameters are checked after those
  closures run and surface as a `ContainerException` wrapping the
  `InvalidArgumentException`. `Injector` rejects invalid arguments and
  unsupported signatures before resolving dependencies.
- Documented that intersection types (`Foo&Bar`) are never autowired, even
  when every member interface is available: only a single named type can be a
  candidate, so the dependency must be provided explicitly (validated against
  both members).
- Added `docs/versioning.md`: the SemVer tiers, the promised API and
  behavioral contracts, and the PHP/PSR-11 support policy. `0.5` is the
  stabilization release that real compositions validate before `1.0` freezes
  the same surface.

### Changed

- `Container` is now final, like `Definitions` and `Injector`: customization
  goes through `Definitions` (`bind()`, factories, `callback()`, `rebind()`,
  `alias()`, `bindConvention()`), never through subclassing. The former
  `build()` / `configure()` hooks and the shared state (`$definitions`,
  `$building`, `$instances`) are private. Breaking since 0.4.1.
- The `Definitions` runtime helpers used by the Container — `hasAlias()`,
  `getAlias()`, `allParametersFor()`, `callbacksForClass()` — are marked
  `@internal`, completing the read API classification.
- Declare `provide: {"psr/container-implementation": "1.0.0"}` in `composer.json`,
  so consumers can depend on the PSR-11 implementation virtual package.

## [0.4.1]

### Fixed

- Development argument validation now checks union, intersection and combined
  types on variadic values before resolving dependencies. Invalid values no
  longer run dependency factories before PHP rejects the call.

### Added

- `Definitions::bindConvention($from, $to)`: derive interface bindings on
  demand from a naming template (e.g. `'App\\{module}\\Domain\\{name}'` =>
  `'App\\{module}\\Infrastructure\\Doctrine{name}'`), with no scan or
  compilation. An explicit `bind()` wins, duplicate templates are rejected,
  ambiguities fail at resolution, and lifecycle matches `bind()`.

## [0.4.0]

### Added

- `Reflection::tryClass($name)`: the cached `ReflectionClass` of an existing class,
  or `null` (also for interfaces and traits). Misses are never cached and
  autoloader exceptions propagate.

### Changed (composition contract)

- Internal PHP classes (`PDO`, `DateTimeImmutable`, `ArrayObject`, `stdClass`...)
  are no longer autowired: `has()` is false unless they are defined. An optional
  parameter such as `?\DateTimeImmutable $now = null` now falls back to its
  default instead of failing on `DateTimeZone::$timezone`.
- Subclasses of `Container` are no longer autowirable either.
- Ids are canonical, at registration and at resolution: a leading backslash is
  dropped (`'\Foo'` is `'Foo'`), and `get('foo')` returns the definition and
  shared instance of `Foo` instead of building a duplicate. Definitions,
  parameters and callbacks registered as `'\Foo'` now belong to `Foo`. A
  namespaced class id written with a different case fails an assertion in
  development. Short custom ids (`'closure'`) are still matched exactly.
- `stdClass` is rejected as an id or an alias in any spelling (`'\stdClass'`,
  `'stdclass'`).
- A missing entry looked up by a factory, a parameter closure or a callback now
  surfaces as a `ContainerException` (previous: the `ReferenceNotFoundException`).
  `NotFoundExceptionInterface` is reserved for the requested id (PSR-11).
- An entry registered under a class or an interface, directly or as an alias,
  must produce an instance of it: `get()` throws a `DefinitionException`
  otherwise, also when assertions are disabled and the id is not loaded yet
  (it is autoloaded when its service is resolved). `set()`/`rebind()` already
  assert it in development. Remapping a class id to an unrelated class is no
  longer allowed: use a custom id.
- `composer/ca-bundle` is no longer a dev dependency.

### Fixed

- `self` and `parent` parameter types are resolved against the declaring class
  (`Reflection::getParameterTypeName()`/`getParameterClass()`, type checks and
  autowiring).
- An anonymous callback key can no longer collide with an explicit numeric name.
- `bind()` rejects `stdClass` as id, like `set()` and `rebind()`.
- `Injector::invoke()` no longer fills its reflection cache with entries that can
  never be hit for non-closure callables.
- Faster `get()` for already resolved entries.
- Reflection caches are keyed by the normalized class name: spelling variants of
  a class (`Foo`, `foo`, `\FOO`) share one entry instead of growing the cache.

## [0.3.2]

### Added

- `Reflection::getParameterTypeName()`: the first non-builtin named type of a
  parameter as a `class-string`, without instantiating a `ReflectionClass`.
  `getParameterClass()` now delegates to it.

## [0.3.1]

*Removed*

## [0.3.0]

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
- **BC (internal):** `Parameters::resolveParameters()` now returns the final
  positional `list<mixed>` ready for a Reflection call (variadic spread
  included), instead of a mixed position/name keyed array that required a
  separate `flattenArguments()` pass. `flattenArguments()` is removed;
  `Injector::invoke()`, `Injector::make()` and `Container` consume the list
   directly. The method remains internal (not part of the public API).

### Changed (parameter resolution)

- **BC:** an object parameter whose union has several available candidates
  (several non-builtin types reported by `has()`) now fails with an
  `UnresolvableParameterException` naming the parameter and the competing
  candidates, instead of resolving the first type in declaration order. An
  explicit argument (or, when no candidate is available, a default value) is
  how you disambiguate. Candidates are never built to disambiguate, and two ids
  stay two candidates even if they could resolve to the same object. The
  current container still counts as a candidate for a parameter typed exactly
  `ContainerInterface`, so a union mixing it with another available type is
  ambiguous too.
- A single available candidate is resolved with `get()`; its failure now
  propagates as-is, without trying another candidate, the default value or
  `null`. `has()` means "candidate available", not "construction guaranteed".

### Changed (container construction)

- Creating a container locks the `Definitions` it receives (`lock()` is
  idempotent; `createContainer()` relies on the Container constructor for
  locking). Mutating definitions
  after a container was built from them now always fails with a
  `DefinitionException`.
- `Container::get()` wraps any error raised while checking existence (the `has()`
  check inside `get()`, e.g. a failing autoloader) in a `ContainerException` with
  the original error as `previous`, so `get()` only throws PSR-11 exceptions. A
  direct `has()` call is not wrapped and surfaces the error as-is.
- Failed resolutions leave the failed service uncached: nothing is cached before
  configure() runs, and the cycle guard only clears the marker of its own call,
  so the next `get()` retries that service — factory included — and factories and
  callbacks must be idempotent. There is no rollback of already-built
  dependencies, side effects or provided instances.

### Changed (internal)

- Simplification of invocation and argument resolution; the internal helper
  `Parameters::valueMatchType()` is removed.

### Fixed

- Callbacks registered on an id remapped to an unrelated class
  (`set(A::class, B::class)` where `B` does not extend `A` and shares no
  interface with it) now run on the produced instance. Ids that are part of the
  instance hierarchy still run exactly once.
- A named variadic argument whose array carries keys (e.g.
  `['first' => 'a']`) no longer fails with an `ArgumentCountError` when
  unpacked: keys are discarded (`array_values`) so the result stays a pure
  list.

### Documentation

- Clarified the boundary: unconditional configuration invariants are always-on
  `DefinitionException`s, while checks that may autoload or reflect otherwise-unused
  code remain development assertions.
- Documented that a composition is validated by tests exercising real configurations
  and entry points, not by an ahead-of-time graph audit.
- Documented argument validation and the absence of general exception wrapping in
  the Injector ([docs/injector.md](./docs/injector.md)), definition locking at
  container construction ([docs/definitions.md](./docs/definitions.md)), and the
  `has()`-inside-`get()` wrapping plus failure guarantees
  ([docs/architecture.md](./docs/architecture.md)).
- Documented the parameter resolution contract: `has()` means "candidate
  available", one candidate is resolved with `get()`, several candidates require
  an explicit argument.
- Corrected the failure contract: a failed resolution leaves the failed service
  uncached, but does not roll back already-built dependencies, side effects or
  provided instances; the next `get()` retries only that service.

See [docs/definitions.md](./docs/definitions.md) for details.
