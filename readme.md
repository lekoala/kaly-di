# Kaly DI

[![Latest Stable Version](http://poser.pugx.org/lekoala/kaly-di/v)](https://packagist.org/packages/lekoala/kaly-di) [![Total Downloads](http://poser.pugx.org/lekoala/kaly-di/downloads)](https://packagist.org/packages/lekoala/kaly-di) [![License](http://poser.pugx.org/lekoala/kaly-di/license)](https://packagist.org/packages/lekoala/kaly-di) [![PHP Version Require](http://poser.pugx.org/lekoala/kaly-di/require/php)](https://packagist.org/packages/lekoala/kaly-di)

> Minimalist and Modern Dependency Injection Container for PHP 8.3+

Kaly DI is a lightweight, flexible, and PSR-11 compliant dependency injection (DI) container designed for modern PHP applications. It emphasizes simplicity, performance, and clear code without reliance on attributes or "magic."

## Key Features

- **PSR-11 Compliance:** Seamlessly interoperable with PHP standards.
- **No Attributes, No Magic:** Keep your code clean and decoupled from the container.
- **Strongly Typed Definitions:** Define dependencies in PHP for full IDE support (autocompletion, refactoring).
- **Advanced Auto-wiring:** Automatic dependency resolution, fine-tunable with resolvers.
- **Performance Focused:** Minimal overhead, designed to be extremely fast.
- **Developer Friendly:** Comprehensive error reporting and development-only assertions.

## Installation

```bash
composer require lekoala/kaly-di
```

## Quick Start

```php
use Kaly\Di\Container;
use Kaly\Di\Definitions;

// 1. Define dependencies
$definitions = Definitions::create()
    ->set(\PDO::class, new \PDO('sqlite::memory:'));

// 2. Create the container
$container = new Container($definitions);

// 3. Get auto-wired instances
$myObject = $container->get(MyClass::class);
```

## Documentation

Detailed guides are available in the `docs/` directory:

- [**Definitions**](./docs/definitions.md): Setting services, binding interfaces, parameters, and callbacks.
- [**Resolvers**](./docs/resolvers.md): Managing complex auto-wiring and type conflicts.
- [**Injector**](./docs/injector.md): Creating fresh instances and invoking callables.
- [**Architecture**](./docs/architecture.md): Internal design, design decisions, and exceptions.

## A Note on Assertions

Kaly DI uses PHP `assert()` for input validation (e.g., checking class existence, definition lock, type compatibility). This is **by design**: these checks run automatically in development (`zend.assertions = 1`) but are disabled in production (`zend.assertions = -1`) for zero overhead.

Ensure your test suite covers your DI configuration to catch mistakes before deployment.

## Examples and Testing

Check the [unit tests](./tests) for comprehensive usage examples covering all features.
