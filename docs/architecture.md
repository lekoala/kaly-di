# Architecture

Kaly DI is designed around three boundaries:

```text
Definitions ─────── Kaly-specific configuration, used at the composition root
      │
      ▼
Kaly resolution rules ── autowire concrete classes, resolve explicit bindings,
      │                   share every get() instance in this Container
      ▼
Container ───────── implements Psr\Container\ContainerInterface
      │             public runtime API: get() / has()
      ▼
application ─────── receives its dependencies directly, not the container

Injector ────────── standalone utility, depends only on ContainerInterface
```

## Core Components

The library is composed of six main classes:

1. **`Container`**: The primary runtime entry point. Implements `Psr\Container\ContainerInterface`. It manages shared instances and orchestrates the building process. Its only public methods are `get()` and `has()`.
2. **`Definitions`**: A fluent builder for container configuration (bindings, parameters, callbacks). Used at the composition root.
3. **`Parameters`** *(internal)*: A static helper using Reflection to analyze callables and match types with container entries.
4. **`Injector`**: A standalone utility for creating fresh instances and invoking callables. It depends only on PSR-11.
5. **`ReflectionCache`** *(internal)*: Caches immutable reflection metadata (constructor signatures and class hierarchy) for the duration of the process.
6. **`Reflection`** *(@api)*: Pure reflection helpers with no container dependency (`getParameterClass`, `getClassName`, `getShortClassName`, `getClassNamespace`). Safe to use anywhere.

## Design Decisions

### Configuration and runtime boundaries

`Definitions` is Kaly's configuration API. It is used at the composition root to
describe bindings, parameters, factories, and callbacks.

`Container` implements `Psr\Container\ContainerInterface`, so code that needs to
consume a container can depend on the standard `get()` / `has()` interface rather
than on Kaly-specific runtime methods.

PSR-11 only defines how entries are retrieved; it does not define how identifiers
are named, how entries are built, or their lifecycle. Kaly deliberately adds its own
simple conventions on top: concrete class names can be autowired, abstractions must
be explicitly bound, and entries returned by `Container::get()` are shared for the
lifetime of that `Container` instance. See
[PSR-11](https://www.php-fig.org/psr/psr-11/).

Application services should normally receive their dependencies directly rather than
depend on the container itself.

### No reserved ids, no self-registration

The container never registers itself and no identifier is reserved:

```php
$container->has(ContainerInterface::class); // false
$container->get(ContainerInterface::class); // ReferenceNotFoundException
```

`ContainerInterface` is an ordinary id: declare it explicitly and you get it back,
otherwise it is not found.

```php
$definitions->set(ContainerInterface::class, $someContainer);
```

Factories and callbacks receive the container as an explicit argument of the
composition code:

```php
$definitions->set(
    Foo::class,
    fn (ContainerInterface $c) => new Foo($c->get(Bar::class)),
);
```

### The resolver can provide the current container

Parameter resolution is a separate concern from container entries. When a constructor
or callable parameter is typed exactly `Psr\Container\ContainerInterface`, the resolver
provides the container it is currently using. This is what lets
`$container->get(Injector::class)` work, since `Injector` requires a container:

```php
$container = new Container();

$injector = $container->get(Injector::class); // new Injector($container)
```

This is a deterministic resolver capability, not a hidden service: there is no name
lookup and no guessing. It matches only the exact `ContainerInterface` type (never a
concrete container class), and an explicit `ContainerInterface` definition wins over
it. Application services should still receive their dependencies directly rather than
depend on the container itself.

### `get()` resolves services, `make()` instantiates classes

`Container::get()` resolves an entry using Kaly's container rules and returns the
shared instance associated with that identifier.

`Injector::make()` creates a fresh instance of a concrete class. It does not read
Kaly `Definitions` for the class being created and does not populate the container's
shared-instance cache.

### The resolution contract

Resolution is intentionally predictable: Kaly DI does not guess dependencies or
lifecycle beyond what was explicitly requested.

```text
get(Foo::class)
    => resolve Foo as a container service
    => configured by Definitions
    => shared for this Container instance

make(Foo::class)
    => instantiate a fresh concrete Foo
    => explicit arguments win
    => missing object dependencies come from PSR-11
    => Definitions for Foo itself are not applied

new service definition
    => set() / bind()

duplicate definition
    => fail
    => never silently override

intentional replacement
    => rebind()
    => existing definition required
    => optional guard: expected current definition (identity)

merge()
    => additive composition
    => conflicts fail before mutation

unresolved required value
    => fail
    => never invent or coerce a value
```

This contract is the acceptance criterion for future features: anything that would
require guessing a dependency, a scope or a lifecycle is out of scope unless it is
explicitly configured. The corresponding user-visible invariants are locked by
`tests/ContractTest.php`.

### Container lifetime

A container's lifetime is the lifetime of its shared services: every entry returned
by `get()` is cached for as long as the container instance lives.

For a long-running worker, a single container can serve the whole process:

```php
$container = new Container($definitions);

// get() services stay shared for the lifetime of this container
```

For request isolation, create a new container per request:

```php
foreach ($requests as $request) {
    $container = new Container($definitions);
    // each container owns its own cache of shared services
}
```

`ReflectionCache` is process-wide: recreating a container does not throw away the
reflection cost, it only resets the service cache.

The same `Definitions` object can be shared by several containers. Whether an entry
is shared *between* those containers depends on how it was declared:

```php
// an object is stored in Definitions: shared between all containers built from them
$definitions->set(Foo::class, new Foo());

// a factory closure produces one instance per container, at first get()
$definitions->set(Foo::class, fn () => new Foo());
```

### `has()` reports known or instantiable entries

`Container::has($id)` returns true for:

- an explicit definition or binding,
- a concrete, instantiable class (auto-wiring).

Interfaces and abstract classes therefore return `false` unless they are bound.
For concrete classes, `has()` reports whether the class itself is instantiable;
constructor resolution may still fail when `get()` is called.

### No Attributes or Annotations

Kaly DI intentionally avoids "magic" attributes. This keeps domain code completely
decoupled from the DI infrastructure. All wiring is done in PHP code, which is easier
to debug, refactor, and type-check.

### Configuration Errors and Assertions

Kaly DI separates two kinds of checks:

- **Unconditional configuration invariants** throw a `DefinitionException`
  regardless of assertion settings: locking, duplicate ids, `merge()` collisions,
  `rebind()` preconditions, and illegal factory results. They are already known and
  cheap to check.
- **Checks that may autoload or reflect code the runtime might never use** (class
  existence, binding compatibility, argument types) use PHP `assert()`. They give
  feedback during development (`zend.assertions = 1`) and cost nothing in production
  (`zend.assertions = -1`), so production never visits services it does not use.

A missing id still throws a `ReferenceNotFoundException` at resolution time.

### No ahead-of-time graph audit

Kaly deliberately does not validate the whole graph before building the container.
Resolving only what is actually used is a feature: services that are never requested
are never autoloaded, reflected or instantiated. The composition is instead validated
by tests that build each real configuration and resolve its real entry points. This
covers the compositions and entry points actually exercised, without maintaining a
parallel static resolver that could drift from runtime behavior.

### No Native Lazy Objects

While PHP 8.4 introduced native Lazy Objects, Kaly DI explicitly chooses not to
implement them. The container focuses on modern, long-running architectures (like
FrankenPHP, Swoole, or RoadRunner) where applications boot once and services are
resolved and cached in memory. Because singletons are already kept alive, the
boot-time performance benefit of lazy loading is negligible. Forcing dependency
instantiation immediately also enforces application correctness by failing fast on
misconfigured graphs.

## Exception Hierarchy

All library exceptions implement `Psr\Container\ContainerExceptionInterface`.
`ReferenceNotFoundException` additionally implements `NotFoundExceptionInterface`
(which itself extends `ContainerExceptionInterface`).

- **`DefinitionException`**: Invalid configuration or composition (locking, duplicate
  ids, merge collisions, rebind preconditions, illegal factory results). Extends
  `LogicException` and implements `ContainerExceptionInterface`, since it can surface
  from `Container::get()`.
- **`ContainerException`**: General container error.
- **`ReferenceNotFoundException`**: Thrown when a service id is requested but not found.
- **`CircularReferenceException`**: Thrown when a dependency chain loops back on itself.
- **`UnresolvableParameterException`**: Thrown when a required parameter cannot be resolved.
