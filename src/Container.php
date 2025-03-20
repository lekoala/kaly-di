<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionNamedType;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Exception;
use ReflectionIntersectionType;

/**
 * A simple yet powerful container that implements strictly the container interface
 *
 * The only public methods are the ones from the interface
 *
 * You can only initialize definitions with the constructor, after that the container is "locked"
 * You may still alter definitions if you have defined them beforehand
 *
 * All returned objects are cached. If you need fresh instances, return factories from the container
 * The creation logic is handled by the Injector
 *
 * Credits to for inspiration
 * @link https://github.com/devanych/di-container
 * @link https://github.com/capsulephp/di
 */
class Container implements ContainerInterface
{
    protected Definitions $definitions;
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

            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            // Collect constructor's arguments. There might be no constructor
            $constructorParameters = $constructor ? $constructor->getParameters() : [];

            $definedParameters = $definitions->allParametersFor($class, $id);

            // Don't resolve object parameters using the container in favor of a custom strategy that allows resolvers
            $arguments = Refl::resolveParameters($constructorParameters, $definedParameters);
            foreach ($constructorParameters as $parameter) {
                // Get parameters from the definitions if set
                $name = $parameter->getName();

                // It is provided by definitions (and not null), skip
                if (isset($definedParameters[$name])) {
                    $arguments[$name] = $definedParameters[$name];
                    continue;
                }

                // Fetch from container based on argument type
                $paramType = $parameter->getType();
                $types = Refl::getParameterTypes($parameter);
                foreach ($types as $type) {
                    if ($type instanceof ReflectionNamedType) {
                        // Built in values will be provided by injector if needed
                        // For union types, these built in types get overwritten if there is a specific resolved type in the container
                        // A built-in type is any type that is not a class, interface, or trait.
                        if ($type->isBuiltin()) {
                            continue;
                        }

                        $typeName = $type->getName();
                        assert(class_exists($typeName) || interface_exists($typeName));

                        // Instantiate classes or interfaces
                        // Check if we have any custom resolver that helps us to map a specific variable for a class to a registered service
                        $serviceName = $definitions->resolveName($name, $typeName, $class);

                        // Find definition where id matches the name of the parameter
                        if ($serviceName && $definitions->has($serviceName)) {
                            $argument = $this->get($serviceName);
                            assert(Refl::valueMatchType($argument, $paramType));
                            $arguments[$name] = $argument;
                            continue;
                        }

                        // Or get based on type
                        if ($this->has($typeName)) {
                            $argument = $this->get($typeName);
                            assert(Refl::valueMatchType($argument, $paramType));
                            $arguments[$name] = $argument;
                            continue;
                        }
                    }
                    if ($type instanceof ReflectionIntersectionType) {
                        $intersectionTypes = $type->getTypes();
                        $currentlyResolved = null;
                        // If all the types resolve to the same object, we can use it
                        foreach ($intersectionTypes as $intersectionType) {
                            if ($intersectionType instanceof ReflectionNamedType) {
                                if ($intersectionType->isBuiltin()) {
                                    continue;
                                }
                                $intersectionTypeName = $intersectionType->getName();
                                if ($this->has($intersectionTypeName)) {
                                    $intersectionArgument = $this->get($intersectionTypeName);
                                    // Since we cache by id, instances are not the same
                                    if ($currentlyResolved && $currentlyResolved::class !== $intersectionArgument::class) {
                                        $currentlyResolved = null;
                                        break;
                                    }
                                    $currentlyResolved = $intersectionArgument;
                                }
                            }
                        }
                        if ($currentlyResolved) {
                            $arguments[$name] = $currentlyResolved;
                        }
                    }
                }

                // It was resolved by injector (eg: a built in type)
                if (array_key_exists($name, $arguments)) {
                    continue;
                }

                // It's optional, we can continue
                if ($parameter->isOptional()) {
                    continue;
                }

                // If we reached this, we didn't manage to create the argument
                throw new UnresolvableParameterException("Unable to create object `$id`, missing parameter: `$name`");
            }

            // Wrap any exception in a ContainerException
            try {
                /** @var object $instance */
                $instance = $reflection->newInstanceArgs($arguments);
            } catch (Exception $e) {
                $type = $e::class;
                throw new ContainerException("Unable to create object `$id`, threw exception: `{$type}` with message `{$e->getMessage()}`");
            }
        } finally {
            unset($this->building[$class]);
        }

        return $instance;
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
        if ($id === Injector::class && $this->definitions->miss(Injector::class)) {
            return new Injector($this);
        }
        // A cached instance does not exist yet, build it
        if (!isset($this->instances[$id])) {
            $instance = $this->build($id);
            // These will run only once since we cache instances
            $this->configure($instance, $id);
            $this->instances[$id] = $instance;
        }
        // Return cached instance
        return $this->instances[$id];
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
        return class_exists($id);
    }

    /**
     * Call additionnal methods after instantiation
     * Callbacks will match based on the class name and the id
     *
     * @param object $instance The instance to configure
     * @param string $id Id in the container
     * @return void
     */
    protected function configure(object $instance, string $id): void
    {
        $definitions = $this->definitions;
        $isInterface = interface_exists($id, false);
        $isClass = class_exists($id, false);
        $instanceClass = $instance::class;
        $callbacks = $isClass ? $definitions->callbacksForClass($id) : $definitions->callbacksFor($id);
        // If $id is an interface or a named service, we may also have class calls
        if ($instanceClass !== $id) {
            $instanceCallbacks = $definitions->callbacksFor($instanceClass);
            if ($isInterface) {
                // interfaces callbacks are executed first and named callbacks are executed last
                $callbacks = array_merge($callbacks, $instanceCallbacks);
            } else {
                // base class callbacks are executed first and service callbacks are executed last
                $callbacks = array_merge($instanceCallbacks, $callbacks);
            }
        }
        foreach ($callbacks as $closure) {
            $closure($instance, $this);
        }
    }

    /**
     * When cloning, clear any cached instance
     *
     * @return void
     */
    public function __clone()
    {
        $this->building = [];
        $this->instances = [];
    }
}
