# Kaly DI

[![Latest Version](https://img.shields.io/packagist/v/lekoala/kaly-di)](https://packagist.org/packages/lekoala/kaly-di) [![Total Downloads](https://img.shields.io/packagist/dt/lekoala/kaly-di)](https://packagist.org/packages/lekoala/kaly-di) [![License](https://img.shields.io/packagist/l/lekoala/kaly-di)](https://packagist.org/packages/lekoala/kaly-di) [![PHP Version Require](https://img.shields.io/packagist/php-v/lekoala/kaly-di)](https://packagist.org/packages/lekoala/kaly-di)

> Small PSR-11 autowiring container for PHP 8.3+

Kaly DI is a lightweight dependency injection container built around a strict
separation of concerns:

- **`Definitions`** — Kaly-specific configuration, used at the composition root.
- **`Container`** — a PSR-11 container. At runtime, the only API is `get()`/`has()`.
- **`Injector`** — an independent utility for building fresh instances and invoking callables.

Application code should normally receive its dependencies directly rather than the
container itself.

## Key Features

- **PSR-11 Compliance:** interoperable with PHP standards.
- **No Attributes, No Magic:** plain PHP configuration, no attributes or compilation.
- **Strongly Typed Definitions:** define dependencies in PHP for full IDE support.
- **Fail-fast composition:** duplicate service definitions are rejected; intentional overrides use `rebind()`.
- **Predictable errors:** configuration invariants throw `DefinitionException`; development-only checks use `assert()`.
- **Autowiring:** concrete classes are resolved automatically; bind interfaces when needed.
- **Predictable `has()`:** true for explicit definitions/bindings or instantiable concrete classes; constructor resolution may still fail in `get()`.
- **Explicit Lifecycle:** `Container::get()` returns shared services, `Injector::make()` instantiates fresh concrete classes.
- **Developer Friendly:** typed error reporting and development-only assertions.

## Installation

```bash
composer require lekoala/kaly-di
```

## Quick Start

```php
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;

// 1. Configure the graph at the composition root
$definitions = Definitions::create()
    ->set(\PDO::class, fn () => new \PDO('sqlite::memory:'))
    ->bind(LoggerInterface::class, FileLogger::class);

// 2. Create the container
$container = new Container($definitions);

// 3. Resolve services (shared)
$pdo = $container->get(\PDO::class);
$logger = $container->get(LoggerInterface::class);

// 4. Build fresh instances with the Injector
$injector = new Injector($container);
$fresh = $injector->make(MyService::class);
```

## `get()` resolves services, `make()` instantiates classes

```php
$container->get(Foo::class);   // shared instance, configured by Definitions
$injector->make(Foo::class);   // fresh concrete instance, independent of Definitions
```

- `Container::get()` resolves configured container entries (definitions, bindings, objects, factories). Entries are shared: two calls with the same id return the same object.
- `Injector::make()` instantiates a concrete class independently of container definitions. It uses PSR-11 only to resolve the object dependencies of that class. An interface or abstract class cannot be built with `make()`.

## Documentation

Detailed guides are available in the `docs/` directory:

- [**Definitions**](./docs/definitions.md): bindings, parameters, callbacks and merging.
- [**Injector**](./docs/injector.md): building fresh instances and invoking callables.
- [**Architecture**](./docs/architecture.md): design decisions and the PSR-11 boundary.

## Reflection Helpers

Pure, dependency-free utilities live in `Kaly\Di\Reflection`:

```php
use Kaly\Di\Reflection;

Reflection::getShortClassName($object); // e.g. "MyService"
Reflection::getClassNamespace(MyService::class); // e.g. "App\Service"
Reflection::getParameterClass($reflectionParameter); // ?ReflectionClass
```

Parameter resolution (`Parameters::resolveParameters()`, `valueMatchType()`,
`flattenArguments()`) is the internal engine of `Container`/`Injector` and is
deliberately not part of the public API: unlike the legacy permissive resolver,
it never invents `''/0/false/[]` defaults and throws
`UnresolvableParameterException` for required parameters that cannot be satisfied.
Argument lists themselves are validated first: unknown named arguments, double
assignments, surplus positionals and positional-after-named are rejected with an
`InvalidArgumentException` before anything is resolved.

## Configuration Errors, Assertions and Composition Tests

Kaly DI puts each check where its cost is reasonable:

- **Unconditional configuration invariants** always throw a `DefinitionException`,
  whether or not assertions are enabled: mutating locked definitions, defining the
  same id twice, a `merge()` collision, rebinding an unknown id, a `rebind()`
  precondition mismatch, or a factory returning an illegal value once it has run.
  These facts are already known and cheap to check.
- **Checks that may autoload or reflect code the runtime might never use** (class
  existence, binding compatibility, argument types) use PHP `assert()`. They run in
  development (`zend.assertions = 1`) and are disabled in production
  (`zend.assertions = -1`), so production never visits services it does not use.
- **The composition is validated by its tests.** There is no ahead-of-time graph
  audit: Kaly resolves only what is actually used. Build each real configuration and
  resolve its real entry points instead:

```php
public function testWebApplicationComposition(): void
{
    $container = webDefinitions()->createContainer();
    $container->get(HttpKernel::class);
}

public function testWorkerComposition(): void
{
    $container = workerDefinitions()->createContainer();
    $container->get(Worker::class);
}
```

This covers the compositions and entry points actually exercised. A factory with a
runtime-dependent branch, or a dynamically computed id, can still introduce a path
your tests did not take; the goal is to cover real configurations, not to prove the
whole graph.

`DefinitionException` extends `LogicException` and also implements the PSR-11
`ContainerExceptionInterface`, because it can surface from `Container::get()` — for
instance when a factory returns something other than an object or a class-string.

## Examples and Testing

Check the [unit tests](./tests) for comprehensive usage examples covering all features.
