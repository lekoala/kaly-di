# Async and long-lived processes

Kaly DI has a single scope: the application. Resolution is synchronous and
must not suspend. Everything request-scoped lives outside the container.

```text
Definitions
    ↓ configuration time

Container
    ↓ application lifetime

Container::get(Foo)
    ↓ shared application instance

Injector::make(Foo)
    ↓ fresh instance
```

> **The container owns application lifetime, not execution context.**

This model works identically under traditional PHP-FPM, FrankenPHP workers,
RoadRunner and Fiber-based runtimes, without Kaly DI knowing which platform
it runs on.

## Resolution is synchronous

Factories, constructors, parameter closures and configuration callbacks must
never suspend (no `Fiber::suspend()`, no `await`, no cooperative yielding).

Valid composition:

```php
$definitions->set(DbClient::class, fn () => new DbClient($config));
```

Invalid composition:

```php
$definitions->set(DbClient::class, function () {
    $connection = await(connectAsync());

    return new DbClient($connection);
});
```

A constructor must not suspend either:

```php
final class Foo
{
    public function __construct()
    {
        await(...); // forbidden inside DI resolution
    }
}
```

The rule is:

```text
DI = composition
I/O = use of services
```

The container may build an async-capable client. Suspending happens later,
when application code uses it:

```php
$repository->find(...); // may suspend the current Fiber
```

There is intentionally no `AsyncContainer`, no `Promise<Service>`, no
`FiberScope` and no waiting on another context: a conflicting concurrent
resolution fails fast through the cycle guard instead of turning the
container into a scheduler.
A factory that suspends and resumes without any other context requesting the
same service still violates the contract, but cannot be detected: the guard
is a cycle diagnostic, not an atomicity mechanism.

## Composition versus business execution

A handler invoked through `Injector::invoke()` resolves its arguments
synchronously first, then runs business logic that may perform async
operations:

```php
$result = $injector->invoke(
    fn (OrderService $orders, string $orderId) => $orders->ship($orderId),
    orderId: $id,
);
// resolving OrderService never suspends; ship() may suspend
```

Keep the two phases separate: synchronous wiring, then potentially
suspending work.

## Shared services

Everything returned by `Container::get()` must be safe to reuse across
requests and, potentially, across concurrent request Fibers.

A service may hold state, but only application-wide or concurrency-safe
state. A connection pool is a good shared service; per-request state is not:

```php
// Good: no request state inside the shared service
final class OrderRepository
{
    public function __construct(
        private DatabaseClient $db,
    ) {}
}
```

```php
// Bad: request state stored on a shared service
final class CurrentUser
{
    public ?User $user = null;
}
```

```php
// Bad when the locale changes per request
final class Translator
{
    public string $currentLocale;
}
```

Prefer passing request-specific values explicitly. A singleton keeps shared
data (catalogues, pools, configuration) and receives the request value as a
method or constructor argument of a fresh object.

A shared object is not automatically dangerous because it is shared: a
database client backed by a pool can serve several Fibers, while a single
connection holding a current transaction cannot. The requirement is not
immutability, it is:

> **Singletons must be reentrant-safe and must not carry request-owned state.**

## Request state stays explicit

There is no request scope, child container or `RequestScope` abstraction.
Application state comes from `Container::get()`, request state from explicit
arguments or an `HttpContext`, fresh objects from `Injector::make()`:

```text
application state → Container::get()
request state     → explicit arguments / HttpContext
fresh object      → Injector::make()
```

Example:

```php
$controller = $injector->make(
    CheckoutController::class,
    request: $request,
    ctx: $ctx,
);
```

A unit of work that needs both is not a container service:

```php
final class CheckoutUnitOfWork
{
    public function __construct(
        DatabaseClient $db,
        HttpContext $ctx,
    ) {}
}

$uow = $injector->make(CheckoutUnitOfWork::class, ctx: $ctx);
```

`DatabaseClient` comes from the container, `HttpContext` from the request.
No child container is needed.

`make()` creates a fresh root: it does not propagate `request`/`ctx`
automatically into its dependencies. Dependencies resolved through the
container stay shared; only the root receives the explicit arguments.

## Blocking initialization belongs at boot

Kaly DI stays lazy: services are built on the first `get()`, not at boot.
Prefer factories that construct resources over factories that perform
potentially blocking initialization:

```php
// Preferred: build the object, connect later through the service API
$definitions->set(Mailer::class, fn () => new Mailer($config));
```

```php
// Avoid when it hides network I/O on the first request:
$definitions->set(PDO::class, fn () => new PDO($dsn));
```

Expensive or blocking setup should happen explicitly during application boot,
or lazily inside a runtime-aware service:

```text
boot
  ↓
create async client object

requests
  ↓
client->query()
  ↓
Fiber suspends cleanly
```

This matters most for long-lived workers and async runtimes: configuration
stays stable for the process lifetime and resolution never performs
unexpected environment or network I/O.

## Failure semantics

Nothing is cached before the instance is fully built *and* configured. If a
factory, constructor or callback throws, the failed service is not cached and
the next `get()` retries it. Factories and callbacks must therefore be
idempotent. There is no rollback of what the failed attempt already produced:
dependencies built earlier stay cached, side effects persist, and an instance
provided directly stays as it was left.
