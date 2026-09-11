# Injector

The `Injector` is a standalone utility that creates fresh instances and invokes
callables while automatically resolving their object dependencies.

It depends only on `Psr\Container\ContainerInterface`. It never reads Kaly
`Definitions`, so `make()` instantiates a concrete class independently of how that
class may be configured in the container.

## Making Classes

`make()` always returns a **new instance** of a concrete class. Its object
dependencies are resolved through the container; every other argument must be
provided explicitly, have a default value, or be nullable.

```php
use Kaly\Di\Injector;

$injector = new Injector($container);

// Create a fresh instance, providing $apiKey explicitly.
// Other object dependencies are fetched from $container.
$service = $injector->make(MyService::class, apiKey: 'secret');
```

You can also pass arguments as an array using spread syntax:

```php
$service = $injector->make(MyService::class, ...['apiKey' => 'secret']);
```

An interface or abstract class cannot be built:

```php
$injector->make(FooInterface::class); // InvalidArgumentException
```

Use `$container->get()` to resolve abstractions.

## Definitions Are Not Consumed by `make()`

Because the Injector is PSR-11-only, constructor parameters declared in `Definitions`
are not applied to the root class being built:

```php
$definitions->parameter(MyService::class, 'apiKey', 'configured');

$container = new Container($definitions);
$injector = new Injector($container);

// 'apiKey' must be provided explicitly; the definition is NOT used here.
$injector->make(MyService::class, apiKey: 'explicit');

// The container does apply the definition (shared instance).
$container->get(MyService::class);
```

## Calling Functions

You can invoke any PHP callable (closures, method arrays, etc.) and let the injector
fill in the arguments from the container.

```php
$fn = function (MyService $service, string $value) {
    return $service->process($value);
};

$result = $injector->invoke($fn, value: 'test-value');
// Or using spread syntax
$result = $injector->invoke($fn, ...['value' => 'test-value']);
```

## Passing an Array of Arguments

If your arguments are stored in an array (e.g., from configuration or user input),
use PHP's spread operator (`...`):

```php
$args = ['apiKey' => 'secret', 'debug' => true];

// For make:
$service = $injector->make(MyService::class, ...$args);

// For invoke:
$result = $injector->invoke($fn, ...$args);

// Positional arguments work too:
$result = $injector->invoke($fn, ...['value1', 'value2']);
```

> **Note:** `make` always returns a **new instance**. It uses the container only to
> resolve missing object dependencies. If you need the container's shared instance,
> use `$container->get()` instead.
