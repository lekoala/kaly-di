# Architecture

Kaly DI is designed around three boundaries:

```text
Definitions ─────── Kaly-specific configuration, used at the composition root
      │
      ▼
Container ───────── implements Psr\Container\ContainerInterface
      │             public runtime API: get() / has()
      │
      ▼
application ─────── depends only on ContainerInterface

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

### Vendor-specific configuration at the composition root; PSR-11 only at runtime

`Definitions` is the Kaly dialect used to build the graph. `Container` is a plain
PSR-11 container. After the bootstrap, application code only ever needs
`Psr\Container\ContainerInterface`, so it can never accidentally depend on a
proprietary container API.

`ContainerInterface::class` is reserved: `$container->get(ContainerInterface::class)`
always returns the container itself. `Definitions` refuses to declare it through any
mutator (`set()`, `bind()`, `parameter()`, `callback()`).

### `get()` resolves services, `make()` instantiates classes

Every service requested through `Container::get()` is cached and therefore shared.
For a fresh instance of a concrete class, use `Injector::make()`, which does not read
Kaly definitions and does not populate the container cache.

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

### `has()` is exact

`Container::has($id)` returns true only when the container can actually provide the
entry:

- the reserved `ContainerInterface` entry,
- an explicit definition or binding,
- a concrete, instantiable class (auto-wiring).

Interfaces and abstract classes therefore return `false` unless they are bound.

### No Attributes or Annotations

Kaly DI intentionally avoids "magic" attributes. This keeps domain code completely
decoupled from the DI infrastructure. All wiring is done in PHP code, which is easier
to debug, refactor, and type-check.

### Assertions for Development

Development-time validation (type checks, class existence, binding compatibility) is
performed using PHP `assert()`. This provides excellent feedback during development
(`zend.assertions = 1`) but ensures zero overhead in production (`zend.assertions = -1`).

Runtime guarantees, by contrast, are enforced with real exceptions and hold regardless
of assertion settings: locking throws a `LogicException`, and the reserved
`ContainerInterface` id throws an `InvalidArgumentException`.

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

- **`ContainerException`**: General container error.
- **`ReferenceNotFoundException`**: Thrown when a service id is requested but not found.
- **`CircularReferenceException`**: Thrown when a dependency chain loops back on itself.
- **`UnresolvableParameterException`**: Thrown when a required parameter cannot be resolved.
