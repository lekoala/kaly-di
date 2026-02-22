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

### Conditional Definitions

You can define services only if they haven't been defined yet.

```php
$definitions->setDefault('logger', MonologLogger::class); // Only sets if 'logger' is not already defined
```

## Binding Interfaces

Use `register`, `bind`, or `bindAll` to map interfaces to concrete implementations.

```php
$definitions->bind(UserInterface::class, MyUser::class);
```

## Setting Parameters

You can explicitly provide values for constructor parameters.

### Positional or Named Parameters

```php
$definitions->parameters(MyClass::class, [
    'apiKey' => 'your-api-key',
    'debug' => true
]);
```

### Referencing Container Services

To pass a service from the container as a parameter, use the `containerParameter` helper.

```php
$definitions->containerParameter(MyClass::class, 'db', 'db.connection');
```

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
