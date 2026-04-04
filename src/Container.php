<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use ReflectionNamedType;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Exception;

/**
 * A PSR-11 compliant dependency injection container
 *
 * Instances are resolved lazily and cached: calling get() with the same id
 * always returns the same object. For fresh instances, use factory closures
 * or the Injector.
 *
 * @link https://github.com/devanych/di-container
 * @link https://github.com/capsulephp/di
 */
class Container implements ContainerInterface
{
    protected readonly Definitions $definitions;
    /**
     * @var array<string,bool>
     */
    protected array $building = [];
    /**
     * @var array<string,object>
     */
    protected array $instances = [];

    /**
     * @param Definitions|array<string,class-string|object|null>|null $definitions
     */
    public function __construct(Definitions|array|null $definitions = null)
    {
        // Create definitions if needed
        if (is_array($definitions) || is_null($definitions)) {
            $definitions = new Definitions($definitions);
        }
        $this->definitions = $definitions;
    }

    /**
     * @param string $id
     * @return object
     * @throws CircularReferenceException
     * @throws ContainerException
     */
    protected function build(string $id): object
    {
        // By default, the id is a class...
        $class = $id;

        // ...but any id can be matched to a class by a specific definition
        $definitions = $this->definitions;

        // If we have a definition
        $definition = $definitions->expand($id);
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
            if (isset($this->building[$class])) {
                $buildChain = implode(', ', array_keys($this->building));
                throw new CircularReferenceException("Circular reference to `$class` in `{$buildChain}`");
            }
            if (!class_exists($class)) {
                throw new ContainerException("Class `$class` does not exist");
            }
            $this->building[$class] = true;

            [$reflection, $constructorParameters] = RuntimeCache::reflection($class);

            $arguments = $this->resolveConstructorArguments($id, $class, $constructorParameters);

            // Wrap any exception in a ContainerException
            try {
                $flatArguments = Parameters::flattenArguments($constructorParameters, $arguments);
                /** @var object $instance */
                $instance = $reflection->newInstanceArgs($flatArguments);
            } catch (Exception $e) {
                $type = $e::class;
                throw new ContainerException("Unable to create object `$id`, threw exception: `{$type}`", 0, $e);
            }
        } finally {
            unset($this->building[$class]);
        }

        return $instance;
    }

    /**
     * Resolve constructor arguments using definitions, resolvers, and container lookups
     *
     * @param string $id The service id being built
     * @param class-string $class The concrete class being instantiated
     * @param \ReflectionParameter[] $constructorParameters
     * @return array<string,mixed>
     * @throws UnresolvableParameterException
     */
    private function resolveConstructorArguments(string $id, string $class, array $constructorParameters): array
    {
        $definitions = $this->definitions;

        // 1. Gather explicitly defined parameters for this class/id
        $definedParameters = $definitions->allParametersFor($class, $id);
        $arguments = [];
        foreach ($definedParameters as $paramName => $paramValue) {
            if ($paramValue instanceof Closure) {
                $arguments[$paramName] = $paramValue($this);
            } else {
                $arguments[$paramName] = $paramValue;
            }
        }

        // 2. Check Resolvers for any missing arguments that map to services
        foreach ($constructorParameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $arguments)) {
                continue;
            }

            $types = Parameters::getParameterTypes($parameter);
            foreach ($types as $type) {
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    assert(class_exists($typeName) || interface_exists($typeName));
                    /** @var class-string $typeName */
                    $serviceName = $definitions->resolveName($name, $typeName, $class);

                    if ($serviceName && $definitions->has($serviceName)) {
                        $arguments[$name] = $this->get($serviceName);
                        break;
                    }
                }
            }
        }

        // 3. Delegate final resolution (type-checks, defaults, nullability, auto-wiring) to Parameters
        try {
            /** @var array<string,mixed> $resolved */
            $resolved = Parameters::resolveParameters($constructorParameters, $arguments, $this, true);
            return $resolved;
        } catch (UnresolvableParameterException $e) {
            // Rethrow with the exact Container error formatting
            throw new UnresolvableParameterException(
                "Unable to create object `$id`, missing parameter: `{$e->getParameterName()}`"
            );
        } catch (\InvalidArgumentException $e) {
            $type = $e::class;
            throw new ContainerException(
                "Unable to create object `$id`, threw exception: `{$type}`",
                0,
                $e
            );
        }
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : object)
     * @throws NotFoundExceptionInterface No entry was found for **this** identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id): object
    {
        // If has($id) returns false, get($id) MUST throw a NotFoundExceptionInterface.
        if ($this->has($id) === false) {
            throw new ReferenceNotFoundException("`$id` is not set");
        }
        // Avoid issues when resolving the container
        if ($id === self::class) {
            return $this;
        }
        // If we need an injector, pass an injector that knows about the container
        if ($id === Injector::class && !$this->definitions->has(Injector::class)) {
            return new Injector($this);
        }
        // Return cached instance
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // A cached instance does not exist yet, build it
        $instance = $this->build($id);
        // These will run only once since we cache instances
        $this->configure($instance, $id);
        $this->instances[$id] = $instance;

        return $instance;
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     */
    public function has(string $id): bool
    {
        // There is a definition for it
        if ($this->definitions->has($id)) {
            return true;
        }
        // Any existing class can be built without definition
        // It's the same has having SomeClass => null as a definition
        return RuntimeCache::classExists($id);
    }

    /**
     * Call additional methods after instantiation
     * Callbacks will match based on the class name and the id
     *
     * @param object $instance The instance to configure
     * @param string $id Id in the container
     * @return void
     */
    protected function configure(object $instance, string $id): void
    {
        $definitions = $this->definitions;
        $instanceClass = $instance::class;

        // Get callbacks defined for the class and its hierarchy (now including interfaces)
        $callbacks = $definitions->callbacksForClass($instanceClass);

        // If requested by a specific ID (that is not the class itself or an interface already covered),
        // we append specific callbacks for that ID.
        if ($id !== $instanceClass && !interface_exists($id, false)) {
            $callbacks = [...$callbacks, ...array_values($definitions->callbacksFor($id))];
        }

        foreach ($callbacks as $closure) {
            $closure($instance, $this);
        }
    }
}
