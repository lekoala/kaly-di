# Versioning

kaly-di follows [SemVer](https://semver.org). Before 1.0, a minor release may
break; after 1.0, only a major does. This page states what the promise covers,
so that "breaking" has a precise meaning before and after 1.0.

Stabilization started with `0.5`. The remaining contract changes are listed
in [UPGRADE.md](../UPGRADE.md). Real compositions such as Kaly validate
that API against their own suites before `1.0` freezes it.

## Tiers

Every public symbol belongs to one of two tiers:

| Tier | Meaning | How to recognize it |
|---|---|---|
| **API** | Stability promised in `1.x` | Everything public that is not `@internal` |
| **Accessible internal** | Public only because the plumbing needs it; may change in any release | `@internal` on the class or method |

Using an `@internal` symbol from an application is a friction to report:
either kaly-di exposes a supported API for the need, or the usage goes away.
`@internal` does not forbid the test suite: tests may inspect internals to
verify a contract.

## Promised API

- `Container` (final): the constructor, `get()`, `has()`.
- `Injector` (final): the constructor, `invoke()`, `make()`.
- `Definitions` (final): configuration — `create()`, the constructor,
  `set()`, `bind()`, `bindConvention()`, `alias()`, `parameter()`,
  `parameters()`, `callback()`, `rebind()`, `merge()`, `lock()`,
  `isLocked()`, `createContainer()`; and the read API — `has()`, `get()`,
  `getAliases()`, `parametersFor()`, `callbacksFor()`.
- `Reflection` (`@api`): `tryClass()`, `getParameterTypeName()`,
  `getParameterClass()`, `getClassName()`, `getShortClassName()`,
  `getClassNamespace()`.
- Exceptions, all final: `ContainerException`,
  `ReferenceNotFoundException`, `DefinitionException`,
  `CircularReferenceException`, `UnresolvableParameterException` with its
  structured accessors `getParameterName()`, `getObjectId()` and
  `getResolutionPath()`. Each implements the PSR-11 marker interface it
  belongs to, so generic consumers can keep catching those.

Behavioral contracts count as API as much as symbols: `get()`/`has()`
semantics, id normalization, the resolution precedence, the sharing model
(shared per container, fresh via the Injector), and the exception rules —
`NotFoundExceptionInterface` only for the requested id, every other failure
normalized by `get()` (see [architecture](./architecture.md)).

## Not promised

- No subclassing: `Container`, `Definitions` and `Injector` are final.
  Customization goes through `Definitions`, not through inherited hooks.
- No compiled container, no scopes, no ahead-of-time graph validation, no
  autowiring of intersection types: these are design decisions, not pending
  features (see [architecture](./architecture.md)).
- Everything marked `@internal`: `Parameters`, `ArgumentGuard`, `TypeMatcher`,
  `ReflectionCache`, `DefinitionGuard`, `AliasValidator`, `ConventionRule`,
  and the `Definitions` plumbing (`getValues()`,
  `getParameters()`, `getCallbacks()`, `hasAlias()`, `getAlias()`,
  `allParametersFor()`, `callbacksForClass()`, `conventionCandidates()`,
  `expand()`).

## Supported versions

| Dependency | Promised |
|---|---|
| PHP | `^8.3` |
| `psr/container` | `^1.1.2 \| ^2.0.2` |

CI runs the suite on PHP 8.3, 8.4 and 8.5, with the lowest supported
dependencies on 8.3, in both assertion modes (`zend.assertions=1` and `-1`):
production behavior must never depend on assertions.
