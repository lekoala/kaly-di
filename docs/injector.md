# Injector

The `Injector` is a standalone utility that can create objects and invoke callables while automatically resolving dependencies.

## Making Classes

You can use `make` to instantiate a class. If you provide a container, the injector will use it to resolve arguments that aren't explicitly provided.

```php
use Kaly\Di\Injector;

$injector = new Injector($container);

// Create a MyClass instance, explicitly providing $apiKey
// Other dependencies will be fetched from $container
$myService = $injector->make(MyClass::class, apiKey: 'secret');
```

You can also pass arguments as an array using spread syntax:

```php
$myService = $injector->make(MyClass::class, ...['apiKey' => 'secret']);
```

## Calling Functions

You can invoke any PHP callable (closures, method arrays, etc.) and let the injector fill in the arguments.

```php
$fn = function (MyService $service, string $v) {
    return $service->process($v);
};

$result = $injector->invoke($fn, v: 'test-value');
// Or using spread syntax
$result = $injector->invoke($fn, ...['v' => 'test-value']);
```

## Using with Interfaces

If the identifier is an interface, the injector can resolve it as long as:

1. You provided a container.
2. The interface is bound to a concrete class in that container.

```php
// Works if LoggerInterface is defined in the container
$logger = $injector->make(LoggerInterface::class);
```

## Passing an Array of Arguments

If your arguments are stored in an array (e.g., from configuration or user input), use PHP's spread operator (`...`) to pass them:

```php
$args = ['apiKey' => 'secret', 'debug' => true];

// For make:
$service = $injector->make(MyClass::class, ...$args);

// For invoke:
$result = $injector->invoke($fn, ...$args);

// Positional arguments work too:
$result = $injector->invoke($fn, ...['value1', 'value2']);
```

> **Note:** `make` always returns a **new instance**, even when using a container.
> It uses the container only to resolve missing constructor arguments.
> If you need the container's cached instance, use `$container->get()` instead.
