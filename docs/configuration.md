# Configuration values, environment and migration

Kaly DI has no equivalent of Symfony's `#[Autowire]` attribute or `%env(...)%`
expression language. The container resolves **object dependencies by type**.
Configuration values (strings, integers, booleans) are resolved once at the
**composition root** and passed explicitly as constructor parameters.

> **Autowire objects; configure values.**

The separation is:

```text
environment / .env
        ↓
application boot
        ↓
typed PHP values
        ↓
Definitions
        ↓
application services
```

Application services never need to know whether a value came from an
environment variable, a PHP default, a config file or a test override.

## From attributes to explicit parameters

A service using framework metadata may look like this:

```php
final readonly class NotificationComposer
{
    public function __construct(
        private TemplateEngineInterface $templates,
        private TranslatorInterface $translator,
        #[Autowire('%env(NOTIFICATION_FROM)%')]
        private string $from,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {}
}
```

With Kaly DI, remove the configuration metadata from the service:

```php
final readonly class NotificationComposer
{
    public function __construct(
        private TemplateEngineInterface $templates,
        private TranslatorInterface $translator,
        private string $from,
        private string $defaultLocale,
    ) {}
}
```

`TemplateEngineInterface` and `TranslatorInterface` are normal object
dependencies: autowired or explicitly bound. The two scalars are configured
at the composition root:

```php
use Kaly\Di\Definitions;

$requiredEnv = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Required environment variable {$name} is missing.");
    }

    return $value;
};

$definitions = Definitions::create()
    ->bind(TranslatorInterface::class, Translator::class)
    ->bind(TemplateEngineInterface::class, NativeTemplateEngine::class)
    ->parameters(
        NotificationComposer::class,
        from: $requiredEnv('NOTIFICATION_FROM'),
        defaultLocale: getenv('APP_DEFAULT_LOCALE') ?: AppDefaults::DEFAULT_LOCALE,
    );
```

The dependency direction is inverted compared to attributes:

```text
Attributes
service class
    ↓
#[Autowire]
    ↓
container/env expression

Kaly DI
composition root
    ↓
resolved PHP value
    ↓
service constructor
```

The service is now ordinary PHP.

`Kaly\Util\Env` (separate optional utility, not a dependency of this library)
may replace the manual `getenv()` helpers above with typed readers such as
`Env::getString()`, `Env::getInt()` and `Env::getBool()`. It is a
convenience, not a requirement.

## Where defaults belong

Distinguish an **application default** from a **deployment setting**.

A value that is part of normal application behaviour belongs in PHP:

```php
final class AppDefaults
{
    public const string DEFAULT_LOCALE = 'en';
}
```

The environment may override it:

```php
$locale = getenv('APP_DEFAULT_LOCALE');
$defaultLocale = is_string($locale) && $locale !== ''
    ? $locale
    : AppDefaults::DEFAULT_LOCALE;
```

Prefer this over committing a `.env` entry just to restate an application
decision. The effective rule is:

```text
PHP default
    ↑ overridden by
environment
```

A fresh checkout then behaves sensibly without requiring an environment
variable for every default.

Values that genuinely depend on the deployment should have no fake default:

```php
$from = $requiredEnv('NOTIFICATION_FROM');
```

is better than inventing `notifications@example.com` when no universally
correct sender exists. Secrets follow the same rule: read them from the
deployment environment and fail fast when a required value is missing.

## Resolve environment values at boot

Environment lookup belongs at the composition root, before the container is
created:

```php
$definitions->parameters(
    NotificationComposer::class,
    from: $requiredEnv('NOTIFICATION_FROM'),
);
```

Avoid lazy configuration unless delayed resolution is actually needed:

```php
// Avoid: hides environment I/O inside resolution
$definitions->parameter(
    NotificationComposer::class,
    'from',
    static fn () => getenv('NOTIFICATION_FROM'),
);
```

Resolving configuration at boot gives a stable composition:

```text
boot
  ├─ load environment
  ├─ apply PHP defaults
  ├─ validate required values
  ├─ convert types
  ├─ build Definitions
  └─ lock composition

requests
  └─ use already configured services
```

This matters especially for long-running workers and async runtimes:
configuration stays stable for the process lifetime and service resolution
performs no unexpected environment or external I/O. `kaly-di` itself never
reads environment variables.

## Prefer typed configuration objects when values form a concept

Direct constructor parameters fit one or two values:

```php
$definitions->parameters(
    NotificationComposer::class,
    from: $from,
    defaultLocale: $defaultLocale,
);
```

When several values belong together or are consumed by multiple services,
use a small typed configuration object:

```php
final readonly class NotificationConfig
{
    public function __construct(
        public string $from,
        public string $defaultLocale,
    ) {}
}
```

Build it once:

```php
$config = new NotificationConfig(
    from: $requiredEnv('NOTIFICATION_FROM'),
    defaultLocale: $defaultLocale,
);

$definitions->set(NotificationConfig::class, $config);
```

The service then depends on the concept, not on primitives:

```php
final readonly class NotificationComposer
{
    public function __construct(
        private TemplateEngineInterface $templates,
        private TranslatorInterface $translator,
        private NotificationConfig $config,
    ) {}
}
```

This pays off once configuration has validation or several related fields,
and avoids repeating primitive wiring across consumers. Do not create a
generic `Config` bag just to avoid constructor parameters: prefer small
objects such as `NotificationConfig`, `DatabaseConfig` or `StorageConfig`.

## Mapping common patterns

| Before (attribute / parameter system) | Kaly DI                                     |
| ------------------------------------- | ------------------------------------------- |
| Type autowiring                       | Type autowiring                             |
| `#[Autowire('%env(FOO)%')]`           | Resolve `FOO` at boot + `parameters()`      |
| `#[Autowire('%some.parameter%')]`     | PHP value + `parameters()`                  |
| Parameter file / `services.yaml`      | PHP constant / configuration at root        |
| `%env(int:PORT)%`                     | `(int)` conversion at boot                  |
| `%env(bool:FEATURE)%`                 | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`  |
| Service binding                       | `Definitions::bind()`                       |
| Explicit service                      | `Definitions::set()`                        |
| Per-service scalar arguments          | `Definitions::parameters()`                 |
| Test service override                 | `Definitions::rebind()`                     |

The goal is not to recreate a parameter system inside Kaly DI. The goal is
to turn external configuration into normal typed PHP values **before** they
enter the dependency graph.

## Complete example

Composition owns the configuration with explicit interface bindings:

```php
$definitions = Definitions::create()
    ->bind(TranslatorInterface::class, Translator::class)
    ->bind(TemplateEngineInterface::class, NativeTemplateEngine::class)
    ->parameters(
        NotificationComposer::class,
        from: $requiredEnv('NOTIFICATION_FROM'),
        defaultLocale: $defaultLocale,
    );

$container = $definitions->createContainer();
$composer = $container->get(NotificationComposer::class);
```

A test composes the same class differently without changing it:

```php
$definitions = Definitions::create()
    ->bind(TranslatorInterface::class, FakeTranslator::class)
    ->bind(TemplateEngineInterface::class, FakeTemplateEngine::class)
    ->parameters(
        NotificationComposer::class,
        from: 'test@example.test',
        defaultLocale: 'en',
    );
```

An existing composition is overridden with `rebind()`, which requires the id
to already be defined:

```php
$definitions->rebind(TranslatorInterface::class, FakeTranslator::class);
```

No environment mutation, attribute reflection or hidden parameter lookup is
involved.

## Rules to remember

- Overrides happen **before** the container is created; building a container
  locks its `Definitions`.
- `rebind()` requires an existing definition: use `set()` or `bind()` for
  new ids.
- Constructor parameters declared in `Definitions` apply to
  `Container::get()`. They are not applied to the root class built by
  `Injector::make()`; see [Injector](./injector.md). Object dependencies of
  that root still come from the container.
