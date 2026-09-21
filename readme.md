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

## A Note on Assertions

Kaly DI distinguishes runtime guarantees from development-time validation:

- **Runtime guarantees** (locking) throw real
  exceptions and therefore always hold, even in production.
- **Development-time validation** (class existence, binding compatibility, argument
  types) uses PHP `assert()`. These checks run in development
  (`zend.assertions = 1`) but are disabled in production (`zend.assertions = -1`)
  for zero overhead.

Ensure your test suite covers your DI configuration to catch mistakes before deployment.

## Examples and Testing

Check the [unit tests](./tests) for comprehensive usage examples covering all features.
