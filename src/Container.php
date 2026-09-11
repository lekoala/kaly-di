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
        // The container always resolves to itself through its interface
        if ($id === ContainerInterface::class) {
            return $this;
        }

        // If has($id) returns false, get($id) MUST throw a NotFoundExceptionInterface.
        if (!$this->has($id)) {
            throw new ReferenceNotFoundException("`{$id}` is not set");
        }

        // Return cached instance
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        // A cached instance does not exist yet, build it
        $instance = $this->build($id);
        // Callbacks run only once since instances are cached
        $this->configure($instance, $id);
        $this->instances[$id] = $instance;

        return $instance;
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * `true` means:
     * - the reserved ContainerInterface entry,
     * - an explicit definition or binding,
     * - a concrete, instantiable class (auto-wiring).
     *
     * Interfaces and abstract classes therefore return false unless bound.
     */
    public function has(string $id): bool
    {
        if ($id === ContainerInterface::class) {
            return true;
        }
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
     * @throws CircularReferenceException
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

        // Use try/finally pattern to make sure we unset building[$id] when throwing exceptions
        try {
            if (array_key_exists($id, $this->building)) {
                $buildChain = implode(', ', array_keys($this->building));
                throw new CircularReferenceException("Circular reference to `{$id}` in `{$buildChain}`");
            }
            if (!class_exists($class)) {
                throw new ContainerException("Class `{$class}` does not exist");
            }
            $this->building[$id] = true;

            [$reflection, $constructorParameters] = ReflectionCache::reflection($class);

            $arguments = $this->resolveConstructorArguments($id, $class, $constructorParameters);

            // Wrap any exception in a ContainerException
            try {
                $flatArguments = Parameters::flattenArguments($constructorParameters, $arguments);
                /** @var object $instance */
                $instance = $reflection->newInstanceArgs($flatArguments);
            } catch (\Throwable $e) {
                $type = $e::class;
                throw new ContainerException("Unable to create object `{$id}`, threw exception: `{$type}`", 0, $e);
            }
        } finally {
            unset($this->building[$id]);
        }

        return $instance;
    }

    /**
     * Resolve constructor arguments using definitions and container lookups
     *
     * @param string $id The service id being built
     * @param class-string $class The concrete class being instantiated
     * @param \ReflectionParameter[] $constructorParameters
     * @return array<string,mixed>
     * @throws UnresolvableParameterException
     */
    private function resolveConstructorArguments(string $id, string $class, array $constructorParameters): array
    {
        // 1. Gather explicitly defined parameters for this class/id
        $arguments = [];
        foreach ($this->definitions->allParametersFor($class, $id) as $paramName => $paramValue) {
            if ($paramValue instanceof Closure) {
                $arguments[$paramName] = $paramValue($this);
                continue;
            }
            $arguments[$paramName] = $paramValue;
        }

        // 2. Delegate final resolution (type-checks, defaults, nullability, auto-wiring) to Parameters
        try {
            /** @var array<string,mixed> */
            return Parameters::resolveParameters($constructorParameters, $arguments, $this);
        } catch (UnresolvableParameterException $e) {
            // Rethrow with the exact Container error formatting
            throw new UnresolvableParameterException(
                "Unable to create object `{$id}`, missing parameter: `{$e->getParameterName()}`",
            );
        } catch (CircularReferenceException $e) {
            // Rethrow circular reference exceptions as-is
            throw $e;
        } catch (\Throwable $e) {
            $type = $e::class;
            throw new ContainerException("Unable to create object `{$id}`, threw exception: `{$type}`", 0, $e);
        }
    }

    /**
     * Call additional methods after instantiation.
     *
     * Callbacks are matched on the class hierarchy. Id-specific callbacks are
     * appended only for custom service ids (not for class or interface ids,
     * which are already covered by the hierarchy).
     *
     * @param object $instance The instance to configure
     * @param string $id Id in the container
     */
    protected function configure(object $instance, string $id): void
    {
        $instanceClass = $instance::class;

        // Get callbacks defined for the class and its hierarchy (including interfaces)
        $callbacks = $this->definitions->callbacksForClass($instanceClass);

        // Id-specific callbacks only apply to custom service ids
        if ($id !== $instanceClass && !class_exists($id) && !interface_exists($id)) {
            $callbacks = [...$callbacks, ...array_values($this->definitions->callbacksFor($id))];
        }

        foreach ($callbacks as $closure) {
            $closure($instance, $this);
        }
    }
}
