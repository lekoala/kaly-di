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

## The Injector Is Not a Recursive Autowirer

`make()` performs **fresh root instantiation**, not an independent recursive object
graph autowiring strategy. Object dependencies of the root class are resolved through
the provided PSR-11 container; if that container cannot provide one, resolution fails
instead of being autowired by the Injector itself.

With `Kaly\Di\Container` this is transparent, because concrete autowirable classes are
reported by `has()`. With a strict third-party PSR-11 container that only exposes its
own explicit entries, a missing object dependency is an error:

```php
use Psr\Container\ContainerInterface;

$strict = new class implements ContainerInterface {
    public function get(string $id): mixed
    {
        throw new \RuntimeException("not found: {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }
};

$injector = new Injector($strict);

// MyService requires a Foo object dependency that $strict does not provide
$injector->make(MyService::class, apiKey: 'secret');
// => UnresolvableParameterException, no recursive fallback
```

This keeps the distinction between a shared service (`get()`) and a fresh object
(`make()`) unambiguous. Either register the missing dependencies in the container, or
pass them explicitly.

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
$handler = fn (string $apiKey, bool $debug): string => $apiKey . ($debug ? '!' : '');
$result = $injector->invoke($handler, ...$args);

// Positional arguments work too:
$concat = fn (string $a, string $b): string => $a . $b;
$result = $injector->invoke($concat, ...['value1', 'value2']);
```

> **Note:** `make` always returns a **new instance**. It uses the container only to
> resolve missing object dependencies. If you need the container's shared instance,
> use `$container->get()` instead.
