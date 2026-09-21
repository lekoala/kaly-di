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

A service id can only be defined once. Setting or binding an id that is already
defined throws a `LogicException`; use `rebind()` to replace it intentionally
(see [Replacing a Definition](#replacing-a-definition)).

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

You can split your definitions across multiple files and merge them. Merging is
**additive**: service definitions are never implicitly replaced. Merging two
definitions that declare the same service id fails with a `DefinitionException`,
even when both declare the exact same value: two modules that both declare a
service own the same decision.

```php
$definitions1 = Definitions::create()->set('repository', UserRepository::class);
$definitions2 = Definitions::create()->set('mailer', Mailer::class);
$definitions1->merge($definitions2); // ok: disjoint ids
```

The check runs before anything is merged, so a failed `merge()` leaves the target
definitions completely unchanged (services, parameters and callbacks alike).

Parameters and callbacks keep their existing merge semantics (later values win
per key): they customize a definition rather than choosing which implementation
owns a service.

### Replacing a Definition

`rebind()` is the only operation that deliberately replaces an existing service
definition. It requires the id to already be defined.

```php
$definitions = applicationDefinitions();

// For a test, a demo, or a separate runtime: start from the real graph and
// intentionally swap one service.
$definitions->rebind(MailerInterface::class, FakeMailer::class);
```

`rebind()` accepts the same values as `set()` (class name, object, closure). When
the id is an interface or an abstract class, the value is checked for
compatibility whenever it is knowable without running user code: a class name or
a concrete object must be compatible, while a closure stays free because its
result is only known at execution time.

```php
$definitions->rebind(MailerInterface::class, FakeMailer::class);       // class name
$definitions->rebind(ClockInterface::class, new FrozenClock($now));    // object
$definitions->rebind(HttpClientInterface::class, fn (ContainerInterface $c) => new FakeHttpClient()); // closure
```

Rebinding an id that does not exist is an error, not a new definition: use
`set()` or `bind()` for that.

#### Guarding a Replacement

`rebind()` accepts an optional `expected` precondition. It then behaves as a
compare-and-swap: the replacement only happens when the id is still defined
*exactly* as the caller assumed.

```php
$definitions->rebind(
    StorageInterface::class,
    InMemoryStorage::class,
    expected: DatabaseStorage::class,
);
```

If the composition changed in the meantime, the call fails instead of silently
building on a stale assumption:

```text
Cannot rebind `StorageInterface`: expected `DatabaseStorage`, currently defined as `CachedStorage`.
```

The comparison is strict identity (`!==`), never structural equality: a
class-string is compared by value, and an object or a closure must be the very
same instance. This is what makes the guard useful for a test, a demo, a mock, a
CLI variant, or a package adapting a known composition: when the main
configuration evolves, the override fails immediately instead of carrying an
outdated hypothesis indefinitely. When the main implementation legitimately
changes, the override is expected to break and be updated.

Use the named `expected:` argument so the intent stays obvious at the call site.

> `rebind()` changes the service for the whole container. It is intended for an
> alternate composition, such as a test or a separate runtime. For one consumer
> that needs a different dependency **inside the same container**, configure that
> consumer explicitly instead:
>
> ```php
> $definitions->parameter(
>     MyService::class,
>     'mailer',
>     fn (ContainerInterface $c) => $c->get(FakeMailer::class),
> );
> ```

### Locking

Once a `Definitions` object is locked, it cannot be modified. This prevents runtime
changes to the container configuration. Any mutator (`set()`, `bind()`, `rebind()`,
`parameter()`, `parameters()`, `callback()`, `merge()`) then throws a
`DefinitionException`.

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

## Configuration Errors and Assertions

Kaly DI puts each check where its cost is reasonable.

**Unconditional configuration invariants** always throw a `DefinitionException`,
whether or not assertions are enabled. They are already known and cheap to check,
and require no autoloading or reflection:

- mutating locked definitions,
- defining the same id twice with `set()` or `bind()`,
- a `merge()` collision,
- rebinding an unknown id,
- a `rebind()` `expected` precondition mismatch,
- a factory returning something other than an object or a class-string (once it has
  been executed).

```php
$definitions->bind(FooInterface::class, Foo::class);
$definitions->bind(FooInterface::class, OtherFoo::class);
// DefinitionException: Service `FooInterface` is already defined.
```

**Checks that may autoload or reflect code the runtime might never use** (class
existence, binding compatibility, argument types) use PHP `assert()`. They give
immediate feedback in development (`zend.assertions = 1`) and are disabled in
production (`zend.assertions = -1`), so production never visits services it does
not use.

`DefinitionException` extends `LogicException` and also implements the PSR-11
`ContainerExceptionInterface`, because it can surface from `Container::get()` — for
instance when a factory returns an illegal value.

## Testing the Composition

There is no ahead-of-time graph audit. Kaly resolves only what is actually used, so
the composition is validated by exercising it: build each real configuration and
resolve its real entry points.

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

## The Container Is Not Registered By Default

No identifier is reserved and the container does not register itself. In particular,
`Psr\Container\ContainerInterface` is not automatically available:

```php
$container->has(ContainerInterface::class); // false
$container->get(ContainerInterface::class); // ReferenceNotFoundException
```

If you want an entry for it (or for any container), declare it explicitly — it then
behaves like any other service:

```php
$definitions->set(ContainerInterface::class, $someContainer);
```

Factories and callbacks receive the container as an argument; they never need it as
an autowired dependency. The resolver does provide the current container to a
constructor or callable parameter typed exactly `Psr\Container\ContainerInterface`,
but that is a resolution capability, not an entry — see the
[architecture notes](./architecture.md).

Note that `parameter()` and `callback()` do not create a definition: `has()` only
reports entries registered with `set()` (or `bind()`).

## Shared vs Fresh

`Definitions` configures what `Container::get()` returns. `get()` always returns a
shared instance. For a fresh instance, see the [Injector](./injector.md).
