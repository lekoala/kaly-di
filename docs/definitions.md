# Definitions

The `Definitions` object is where you configure your dependency injection container. It provides a fluent API for mapping identifiers to classes, objects, or closures.

## Setting Services

### Basic Mapping

Typically, the identifier is a class or interface name, but it can be any unique string.

```php
use Kaly\Di\Definitions;

$definitions = Definitions::create()
    ->set(\PDO::class, new \PDO('sqlite::memory:')) // Map to a specific object
    ->set('app.cache', MyCache::class);            // Map to a class name (auto-wired)
```

## Binding Interfaces

Use `register`, `bind`, or `bindAll` to map interfaces to concrete implementations.

```php
$definitions->bind(UserInterface::class, MyUser::class);
```

## Setting Parameters

You can explicitly provide values for constructor parameters.

### Single Parameter

```php
$definitions->parameter(MyClass::class, 'apiKey', 'your-api-key');
```

### Multiple Parameters with Named Arguments

```php
$definitions->parameters(MyClass::class, [
    'apiKey' => 'your-api-key',
    'debug' => true
]);
```

### Referencing Container Services

To pass a service from the container as a parameter, pass a closure that receives the container:

```php
$definitions->parameter(MyClass::class, 'db', fn($container) => $container->get('db.connection'));
```

The closure is resolved lazily when the object is instantiated, so it always gets the current state of the container.

### Dynamic Resolution

Since closures receive the container, you can implement conditional logic:

```php
$definitions->parameter(CacheService::class, 'driver', function($container) {
    // Use Redis if available, fall back to file cache
    if ($container->has(RedisConnection::class)) {
        return $container->get(RedisConnection::class);
    }
    return $container->get(FileCache::class);
});
```

This is useful for environment-based configuration, feature flags, or graceful degradation when optional services are not available.

## Registering Callbacks

Callbacks allow you to configure objects after they are instantiated.

```php
$definitions->callback(MyService::class, function (MyService $service) {
    $service->initialize();
});
```

Callbacks can be registered for:

- Specific class or service name
- Implementation of an interface
- Inheritance from a parent class

If multiple callbacks apply, they are executed in a deterministic order (interfaces first, then parents, then the concrete class).

## Merging and Locking

### Merging

You can split your definitions across multiple files and merge them.

```php
$definitions1 = Definitions::create()->set('a', 'v1');
$definitions2 = Definitions::create()->set('b', 'v2');
$definitions1->merge($definitions2);
```

### Locking

Once a `Definitions` object is locked, it cannot be modified. This is useful for preventing runtime changes to the container configuration.

```php
$definitions->lock();
// $definitions->set('c', 'v3'); // Throws an error (in development)
```

### Creating the Container

`createContainer()` is the terminal method of the fluent chain. It creates the container and automatically locks the definitions:

```php
$container = Definitions::create()
    ->set(PDO::class, fn() => new PDO('sqlite::memory:'))
    ->createContainer(); // definitions are now locked
```

If you need to modify definitions after creating a container, create them first and pass them manually:

```php
$definitions = Definitions::create()->set(...);
$container = new Container($definitions);
// $definitions can still be modified
```

## Getting Fresh Instances

By default, the container caches instances. If you need a fresh instance every time:

1. **Use a Factory Closure:**

   ```php
   $definitions->set(MyClass::class, fn() => new MyClass());
   ```

2. **Use the Injector:**
   The `Injector::make()` method always returns a fresh instance.

   ```php
   $injector = new Injector($container);
   $fresh = $injector->make(MyClass::class);
   ```
