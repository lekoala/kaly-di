<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * A PSR-11 compliant dependency injection container.
 *
 * Instances are resolved lazily and shared: calling get() with the same id
 * always returns the same object. To create a fresh instance of a concrete
 * class, use the Injector.
 *
 * The runtime API is deliberately limited to ContainerInterface::get() and
 * has(). Configuration lives in Definitions and is only used at the
 * composition root.
 *
 * @link https://github.com/devanych/di-container
 * @link https://github.com/capsulephp/di
 */
class Container implements ContainerInterface
{
    protected readonly Definitions $definitions;

    /**
     * Ids that must never be auto-wired by the container, otherwise it would
     * silently build a second container or an empty Definitions.
     *
     * @var list<class-string>
     */
    private const NOT_AUTO_WIRED = [
        self::class,
        Definitions::class,
    ];

    /**
     * @var array<string,true>
     */
    protected array $building = [];

    /**
     * @var array<string,object>
     */
    protected array $instances = [];

    /**
     * @param Definitions|array<string,class-string|object>|null $definitions
     */
    public function __construct(Definitions|array|null $definitions = null)
    {
        // Create definitions if needed
        if (is_array($definitions) || is_null($definitions)) {
            $definitions = new Definitions($definitions ?? []);
        }
        // Locking is idempotent: once the composition root has a container,
        // definitions must not change anymore.
        $definitions->lock();
        $this->definitions = $definitions;
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : object)
     * @throws NotFoundExceptionInterface No entry was found for **this** identifier.
     * @throws \Psr\Container\ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id): object
    {
        // If has($id) returns false, get($id) MUST throw a NotFoundExceptionInterface.
        // has() is read-only but may autoload or reflect the id: any failure there is
        // normalized so get() only ever throws PSR-11 exceptions. A direct has()
        // call is not wrapped and may surface the underlying error as-is.
        try {
            $exists = $this->has($id);
        } catch (\Throwable $e) {
            $type = $e::class;
            throw new ContainerException("Unable to check `{$id}`, threw exception: `{$type}`", 0, $e);
        }
        if (!$exists) {
            throw new ReferenceNotFoundException("`{$id}` is not set");
        }

        // An alias delegates to its target: same instance, configured once.
        if ($this->definitions->hasAlias($id)) {
            return $this->instances[$id] ??= $this->get((string) $this->definitions->getAlias($id));
        }

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        // Guard the whole resolution: factory closures, constructor and callbacks.
        // Rejected before the marker is set: a recursive call refused here must
        // not touch the marker owned by the outer call.
        if (array_key_exists($id, $this->building)) {
            $buildChain = implode(', ', array_keys($this->building));
            throw new CircularReferenceException("Circular reference to `{$id}` in `{$buildChain}`");
        }

        // Marker set before the try: the finally below only ever cleans the
        // marker of its own call.
        $this->building[$id] = true;

        try {
            // A cached instance does not exist yet: build, configure and cache it
            $instance = $this->build($id);
            // Callbacks run only once since instances are cached
            $this->configure($instance, $id);
            // Nothing is cached before configure(): a failed resolution leaves
            // the failed service uncached (no rollback of already-built
            // dependencies, side effects or provided instances).
            $this->instances[$id] = $instance;

            return $instance;
        } catch (
            DefinitionException|ReferenceNotFoundException|CircularReferenceException|UnresolvableParameterException|ContainerException $e
        ) {
            // Preserve our own exceptions, wrap any other (including third-party PSR ones)
            throw $e;
        } catch (\Throwable $e) {
            $type = $e::class;
            throw new ContainerException("Unable to create object `{$id}`, threw exception: `{$type}`", 0, $e);
        } finally {
            unset($this->building[$id]);
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * `true` means:
     * - an explicit definition or binding,
     * - a concrete, instantiable class (auto-wiring).
     *
     * Interfaces and abstract classes therefore return false unless bound. For a
     * concrete class, `has()` only reports that the class itself is instantiable:
     * resolving its constructor arguments may still fail in `get()`.
     */
    public function has(string $id): bool
    {
        // There is an explicit definition for it
        if ($this->definitions->has($id)) {
            return true;
        }
        // Never auto-wire the container internals
        if (in_array($id, self::NOT_AUTO_WIRED, true)) {
            return false;
        }
        // Any concrete instantiable class can be built without definition
        return ReflectionCache::isInstantiable($id);
    }

    /**
     * Build the object matching an id.
     *
     * The cycle guard, exception normalization and instance caching are handled
     * by get(), which wraps the whole resolution process.
     *
     * @throws ContainerException
     */
    protected function build(string $id): object
    {
        // By default, the id is a class...
        $class = $id;

        // ...but any id can be matched to a class or an object by a specific definition
        $definition = $this->definitions->expand($id, $this);
        if ($definition !== null) {
            // Can be an instance of something or the result of a closure
            // eg: 'app' => $app or 'app' => fn () => new App
            if (is_object($definition)) {
                return $definition;
            }

            // Can be an interface binding
            // eg: SomeInterface::class => MyClass::class
            $class = $definition;
        }

        if (!class_exists($class)) {
            throw new ContainerException("Class `{$class}` does not exist");
        }

        [$reflection, $constructorParameters] = ReflectionCache::reflection($class);

        $arguments = $this->resolveConstructorArguments($id, $class, $constructorParameters);

        // Wrap any exception in a ContainerException
        try {
            /** @var object $instance */
            $instance = $reflection->newInstanceArgs($arguments);
        } catch (\Throwable $e) {
            $type = $e::class;
            throw new ContainerException("Unable to create object `{$id}`, threw exception: `{$type}`", 0, $e);
        }

        return $instance;
    }

    /**
     * Resolve constructor arguments using definitions and container lookups
     *
     * @param string $id The service id being built
     * @param class-string $class The concrete class being instantiated
     * @param \ReflectionParameter[] $constructorParameters
     * @return list<mixed>
     * @throws UnresolvableParameterException
     */
    private function resolveConstructorArguments(string $id, string $class, array $constructorParameters): array
    {
        // Gather explicitly defined parameters for this class/id
        $configured = $this->definitions->allParametersFor($class, $id);
        $this->assertKnownParameters($id, $class, $configured, $constructorParameters);

        $arguments = [];
        foreach ($configured as $paramName => $paramValue) {
            if ($paramValue instanceof Closure) {
                try {
                    $arguments[$paramName] = $paramValue($this);
                } catch (UnresolvableParameterException $e) {
                    // A configured closure can resolve a nested service. Prefix
                    // the path with this configured parameter so it does not
                    // lose the outer frame.
                    throw $this->wrapUnresolvableParameter($e, $id, (string) $paramName);
                }
                continue;
            }
            $arguments[$paramName] = $paramValue;
        }

        // Final resolution (type-checks, defaults, nullability, auto-wiring).
        // Only UnresolvableParameterException needs handling here: it gains the
        // structured path. Anything else propagates to get(), which already
        // preserves Kaly exceptions and wraps the rest.
        try {
            return Parameters::resolveParameters($constructorParameters, $arguments, $this);
        } catch (UnresolvableParameterException $e) {
            // Rethrow with the exact Container error formatting, using the
            // immediate parameter name (not the nested one).
            throw $this->wrapUnresolvableParameter($e, $id, $e->getParameterName());
        }
    }

    /**
     * Reformat an unresolvable parameter error, prefixing the structured path
     * with this object and parameter:
     * `Root::$middle -> Middle::$leaf -> Leaf::$apiKey`.
     *
     * The immediate parameter name (not the nested one) keeps the message
     * faithful to the graph. Never derived from message text.
     */
    private function wrapUnresolvableParameter(
        UnresolvableParameterException $e,
        string $id,
        ?string $parameterName,
    ): UnresolvableParameterException {
        $segment = $parameterName !== null ? "{$id}::\${$parameterName}" : $id;
        $nestedPath = $e->getResolutionPath();
        $path = $nestedPath !== null ? "{$segment} -> {$nestedPath}" : $segment;
        $message = $parameterName !== null
            ? "Unable to create object `{$id}`, cannot resolve parameter: `{$parameterName}`"
            : "Unable to create object `{$id}`: {$e->getMessage()}";
        if ($nestedPath !== null) {
            $message .= "\nPath: {$path}";
        }

        return new UnresolvableParameterException($message, 0, $e, $parameterName, $id, $path);
    }

    /**
     * Reject configured parameters that do not exist on the constructor.
     *
     * The constructor signature is already loaded, so this costs nothing and
     * needs no graph audit. It runs before parameter closures are executed so
     * an already invalid configuration cannot trigger side effects.
     *
     * @param class-string $class
     * @param array<string,mixed> $configured
     * @param \ReflectionParameter[] $constructorParameters
     * @throws DefinitionException
     */
    private function assertKnownParameters(
        string $id,
        string $class,
        array $configured,
        array $constructorParameters,
    ): void {
        $available = array_values(array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $constructorParameters,
        ));
        $unknown = array_values(array_diff(array_keys($configured), $available));

        if ($unknown === []) {
            return;
        }

        sort($unknown);
        $target = $id === $class ? "`{$class}`" : "`{$class}` (id `{$id}`)";
        throw new DefinitionException(
            'Unknown configured parameter(s) for '
            . $target
            . ': '
            . self::quoteList($unknown)
            . '. Available: '
            . self::quoteList($available)
            . '.',
        );
    }

    /**
     * @param list<string> $names
     */
    private static function quoteList(array $names): string
    {
        if ($names === []) {
            return '(none)';
        }
        return implode(', ', array_map(static fn(string $name): string => "`{$name}`", $names));
    }

    /**
     * Call additional methods after instantiation.
     *
     * Callbacks are matched on the class hierarchy. Id-specific callbacks are
     * appended whenever the id is not already covered by that hierarchy: a
     * custom id, but also a class id remapped to an unrelated class
     * (set(A::class, B::class) where B does not extend A). When the id IS part
     * of the hierarchy (the instance's own class, a parent, an interface),
     * callbacksForClass() already collected them and appending again would run
     * them twice.
     *
     * @param object $instance The instance to configure
     * @param string $id Id in the container
     */
    protected function configure(object $instance, string $id): void
    {
        $instanceClass = $instance::class;

        // Get callbacks defined for the class and its hierarchy (including interfaces)
        $callbacks = $this->definitions->callbacksForClass($instanceClass);

        // Id-specific callbacks unless the id is already covered by the hierarchy
        if ($id !== $instanceClass) {
            $hierarchy = ReflectionCache::classHierarchy($instanceClass);
            $covered = in_array($id, $hierarchy['interfaces'], true) || in_array($id, $hierarchy['parents'], true);
            if (!$covered) {
                $callbacks = [...$callbacks, ...array_values($this->definitions->callbacksFor($id))];
            }
        }

        foreach ($callbacks as $closure) {
            $closure($instance, $this);
        }
    }
}
