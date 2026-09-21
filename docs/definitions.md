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

You can also build definitions from an array of `id => class|object|closure`:

```php
$definitions = Definitions::create([
    \PDO::class => fn () => new \PDO('sqlite::memory:'),
    LoggerInterface::class => FileLogger::class,
]);
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
use Psr\Container\ContainerInterface;

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

`bind()` means **"when `$abstract` is requested, build `$concrete` for this id"**. It
does not mean "`$abstract` is an alias of the `$concrete` entry". The two ids are
resolved and cached independently:

```php
$definitions->bind(FooInterface::class, Foo::class);

$a = $container->get(FooInterface::class);
$b = $container->get(Foo::class);

$a === $b; // false: two distinct shared instances
```

When you actually want an alias, express it explicitly with a factory that returns
the target service:

```php
use Psr\Container\ContainerInterface;

$definitions->set(
    FooInterface::class,
    fn (ContainerInterface $c) => $c->get(Foo::class),
);

$container->get(FooInterface::class) === $container->get(Foo::class); // true
```

There is deliberately no `alias()` API: if the pattern becomes common in real
applications, it will be added based on actual usage.

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

A callback receives the instance, then the container:

```php
$definitions->callback(MyService::class, function (MyService $service, ContainerInterface $c) {
    $service->setup($c->get(Config::class));
});
```

## Merging and Locking

### Merging

You can split your definitions across multiple files and merge them. Later values win.

```php
$definitions1 = Definitions::create()->set('repository', UserRepository::class);
$definitions2 = Definitions::create()->set('mailer', Mailer::class);
$definitions1->merge($definitions2);
```

### Locking

Once a `Definitions` object is locked, it cannot be modified. This prevents runtime
changes to the container configuration. Any mutator (`set()`, `bind()`, `parameter()`,
`parameters()`, `callback()`, `merge()`) then throws a `LogicException`.

Locking is enforced at runtime, whether or not assertions are enabled, so the
guarantee holds in production too.

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
`Definitions` refuses to declare it through any mutator (`set()`, `bind()`,
`parameter()`, `callback()`). This lets factories and infrastructure objects receive
the container without ever knowing the concrete `Kaly\Di\Container`.

## Shared vs Fresh

`Definitions` configures what `Container::get()` returns. `get()` always returns a
shared instance. For a fresh instance, see the [Injector](./injector.md).
