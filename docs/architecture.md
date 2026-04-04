# Architecture

Kaly DI is designed with simplicity, performance, and PSR-11 compliance as core principles.

## Core Components

The library is composed of five main classes:

1. **`Container`**: The primary entry point. Implements `Psr\Container\ContainerInterface`. It manages shared object instances and orchestrates the building process.
2. **`Definitions`**: A fluent builder for container configuration. It stores service mappings, parameters, and callbacks.
3. **`ResolverRegistry`** *(internal)*: Internal component that manages complex auto-wiring rules.
4. **`Parameters`**: A static helper using Reflection to analyze constructor signatures and match types with container entries.
5. **`Injector`**: A utility for "on-demand" instantiation and invocation.

## Design Decisions

### No Attributes or Annotations

Kaly DI intentionally avoids "magic" attributes. This keeps your domain code completely decoupled from the DI infrastructure. All wiring is done in PHP code, which is easier to debug, refactor, and type-check.

### Assertions for Development

Most input validation (type checks, lock enforcement, class existence) is performed using PHP `assert()`. This provides excellent feedback during development (`zend.assertions = 1`) but ensures zero overhead in production (`zend.assertions = -1`).

### Cache by Default

The container adheres to the principle of "invariable results". Every requested service is cached after its first instantiation. For factory-like behavior, use a closure in your definitions.

### No Native Lazy Objects

While PHP 8.4 introduced native Lazy Objects (via `ReflectionClass::newLazyGhost()` and `newLazyProxy()`), Kaly DI explicitly chooses not to implement them. The container focuses on modern, long-running architectures (like FrankenPHP, Swoole, or RoadRunner). In these environments, applications boot once and dependencies are resolved and cached in memory across thousands of requests. Because singletons are already kept alive, the boot-time performance benefit of lazy loading is negligible. Furthermore, forcing dependency instantiation immediately enforces application correctness by failing fast on misconfigured graphs, rather than failing in the middle of request execution.

## Exception Hierarchy

All library exceptions implement `Psr\Container\ContainerExceptionInterface`.

- **`ContainerException`**: Base exception for general container errors.
- **`ReferenceNotFoundException`**: Thrown when a service ID is requested but not found.
- **`CircularReferenceException`**: Thrown when a dependency chain loops back on itself.
- **`UnresolvableParameterException`**: Thrown when a required constructor parameter cannot be auto-wired.
