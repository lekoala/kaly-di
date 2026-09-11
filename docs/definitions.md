# Definitions

The `Definitions` object is where you configure your dependency injection container.
It provides a fluent API for mapping identifiers to classes, objects, or closures,
and for declaring constructor parameters and post-construction callbacks.

The primitives are orthogonal: each method does exactly one thing.

```php
use Kaly\Di\Definitions;

$definitions = Definitions::create()
    ->set(\PDO::class, fn () => new \PDO('sqlite::memory:'))
    ->bind(LoggerInterface::class, FileLogger::class)
    ->parameters(FileLogger::class, path: '/var/log/app.log')
    ->callback(FileLogger::class, fn (FileLogger $logger) => $logger->open());
```

## Setting Services

The identifier is usually a class or interface name, but it can be any unique string.

```php
$definitions
    ->set(\PDO::class, new \PDO('sqlite::memory:')) // an object instance
    ->set('app.cache', MyCache::class);             // a class name (auto-wired)
```

A closure is a lazy factory: it is executed the first time the id is requested by
`get()`, and its result is then shared. The closure receives the container.

```php
$definitions->set(LoggerInterface::class, function (ContainerInterface $c) {
    return new FileLogger($c->get(Config::class));
});
```

> `null` is not a valid definition. Concrete classes are already auto-wired, so a
> `null` entry would add nothing.

## Binding Abstractions

Use `bind($abstract, $concrete)` to map an interface or an abstract class to a
concrete implementation.

```php
$definitions->bind(UserRepositoryInterface::class, SqlUserRepository::class);
$definitions->bind(AbstractHandler::class, LoggingHandler::class);
```

The concrete class must be compatible with the abstraction. Configuration stays
explicit: there is no "single interface" auto-detection.

## Setting Parameters

You can explicitly provide values for constructor parameters.

```php
$definitions
    ->parameter(MyClass::class, 'apiKey', 'your-api-key')
    ->parameters(MyClass::class, debug: true, retries: 3);
```

When an id is bound to a class, parameters can be declared for either the id or the
concrete class. The id takes precedence.

### Referencing Container Services

To pass a service from the container as a parameter, use a closure that receives the
container:

```php
$definitions->parameter(
    MyClass::class,
    'db',
    fn (ContainerInterface $c) => $c->get('db.connection'),
);
```

The closure is resolved lazily when the object is instantiated, so it always sees the
current state of the container.

## Registering Callbacks

Callbacks configure objects after they are instantiated.

```php
$definitions->callback(MyService::class, function (MyService $service) {
    $service->initialize();
});
```

Callbacks can be registered for:

- a specific class or service id,
- an implementation of an interface,
- an inheritance from a parent class.

If multiple callbacks apply, they are executed in a deterministic order: interfaces
(alphabetically), then parents (top to bottom), then the concrete class, and finally
id-specific callbacks.

## Merging and Locking

### Merging

You can split your definitions across multiple files and merge them. Later values win.

```php
$definitions1 = Definitions::create()->set('a', 'v1');
$definitions2 = Definitions::create()->set('b', 'v2');
$definitions1->merge($definitions2);
```

### Locking

Once a `Definitions` object is locked, it cannot be modified. This prevents runtime
changes to the container configuration.

```php
$definitions->lock();
```

### Creating the Container

`createContainer()` is the terminal method of the fluent chain. It locks the
definitions and creates the container:

```php
$container = Definitions::create()
    ->set(PDO::class, fn () => new PDO('sqlite::memory:'))
    ->createContainer();
```

## The Reserved `ContainerInterface` Id

`Psr\Container\ContainerInterface` is reserved by the container:
`$container->get(ContainerInterface::class)` always returns the container itself, and
`Definitions` refuses to `set()` or `bind()` that id. This lets factories and
infrastructure objects receive the container without ever knowing the concrete
`Kaly\Di\Container`.

## Shared vs Fresh

`Definitions` configures what `Container::get()` returns. `get()` always returns a
shared instance. For a fresh instance, see the [Injector](./injector.md).
