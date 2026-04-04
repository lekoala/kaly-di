<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use stdClass;

/**
 * Fluent builder for container configuration
 *
 * Maps identifiers (class names, interfaces, or custom strings) to class names,
 * objects, or factory closures. Also manages parameters, callbacks, and resolvers.
 *
 * Validation checks (class_exists, lock state) use assert() for zero production overhead.
 *
 * @see https://github.com/capsulephp/di/issues/6
 */
final class Definitions
{
    /**
     * Store definitions as a map
     * Typically, the key is a class name or a custom id
     * Class strings are resolved to a class instance while objects are returned as is
     * If the object is a Closure, it is executed as a factory method (see expand)
     * @var array<string,class-string|object|null>
     */
    protected array $values = [];

    /**
     * Defines callbacks to be called after an object is instantiated
     * @var array<string,array<string,callable>>
     */
    protected array $callbacks = [];

    /**
     * Defines parameters passed to a given id
     * @var array<string,array<string,mixed>>
     */
    protected array $parameters = [];

    /**
     * Resolve arguments based on custom conditions
     */
    protected readonly ResolverRegistry $resolverRegistry;

    /**
     * Lock status
     */
    protected bool $locked = false;

    /**
     * You can create the definitions with a basic array that map interfaces/ids to a class name or a closure
     *
     * @param array<string,class-string|object|null>|Definitions|null $definitions
     */
    public function __construct(array|Definitions|null $definitions = null)
    {
        $this->resolverRegistry = new ResolverRegistry();
        if ($definitions === null) {
            return;
        }
        // Passing an array is like a call to set with key, value
        if (is_array($definitions)) {
            $this->setAll($definitions);
        } else {
            $this->merge($definitions);
        }
    }

    /**
     * Pre PHP 8.4 helper for a better syntax
     *
     * @param array<string,class-string|object>|Definitions|null $definitions
     */
    public static function create(array|Definitions|null $definitions = null): self
    {
        return new Definitions($definitions);
    }

    public function merge(Definitions $definitions): void
    {
        $this->values = [...$this->values, ...$definitions->getValues()];

        foreach ($definitions->getCallbacks() as $key => $values) {
            if (isset($this->callbacks[$key])) {
                $this->callbacks[$key] = [...$this->callbacks[$key], ...$values];
            } else {
                $this->callbacks[$key] = $values;
            }
        }

        foreach ($definitions->getParameters() as $key => $values) {
            if (isset($this->parameters[$key])) {
                $this->parameters[$key] = [...$this->parameters[$key], ...$values];
            } else {
                $this->parameters[$key] = $values;
            }
        }

        $resolvers = $this->resolverRegistry->getResolvers();
        foreach ($definitions->getResolvers() as $key => $values) {
            if (isset($resolvers[$key])) {
                $resolvers[$key] = [...$resolvers[$key], ...$values];
            } else {
                $resolvers[$key] = $values;
            }
        }
        $this->resolverRegistry->setResolvers($resolvers);
    }

    /**
     * @return array<string,class-string|object|null>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return array<string,array<string,callable>>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<class-string,array<string,callable|string>>
     */
    public function getResolvers(): array
    {
        return $this->resolverRegistry->getResolvers();
    }

    /**
     * Create a container from these definitions and lock them.
     *
     * This is a terminal method in the fluent chain. After calling it,
     * the definitions are locked and cannot be modified further.
     *
     * @return Container
     */
    public function createContainer(): Container
    {
        $this->lock();
        return new Container($this);
    }

    public function sort(): void
    {
        ksort($this->values);
        ksort($this->callbacks);
        ksort($this->parameters);
        $this->resolverRegistry->sort();
    }

    /**
     * Check if entry exists
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        // A value can be null
        // isset() does not return true for array keys that correspond to a null value, while array_key_exists() does.
        // see https://www.php.net/manual/en/function.array-key-exists.php
        return array_key_exists($id, $this->values);
    }

    /**
     * Get an entry
     *
     * @param string $id
     * @return class-string|object|null
     */
    public function get(string $id): mixed
    {
        return $this->values[$id] ?? null;
    }

    /**
     * Similar to get, but expand any lazy closure
     *
     * @param string $id
     * @return string|object|null
     */
    public function expand(string $id): string|object|null
    {
        $entry = $this->get($id);
        // If we have a closure, run it to get a string or an object
        if ($entry && $entry instanceof Closure) {
            $entry = $entry($this, $this->parametersFor($id));
            assert(is_object($entry) || is_string($entry));
        }
        return $entry;
    }

    /**
     * Add an entry
     *
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    public function set(string $id, string|object|null $value = null): self
    {
        assert(!$this->locked);
        assert(is_null($value) || is_object($value) || class_exists($value), "Value for `$id` is not valid");
        // Avoid resolving stdClass with the DI container
        assert($id !== stdClass::class, "Cannot set stdClass as id");
        $this->values[$id] = $value;
        return $this;
    }

    /**
     * Add an entry if not set yet (internal use only)
     *
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    protected function setDefault(string $id, string|object|null $value = null): self
    {
        if ($this->has($id)) {
            return $this;
        }
        return $this->set($id, $value);
    }

    /**
     * @param array<string,object|class-string|null> $definitions
     * @return void
     */
    public function setAll(array $definitions): void
    {
        foreach ($definitions as $k => $v) {
            $this->set($k, $v);
        }
    }

    /**
     * Register an instance
     * Binds to all classes and interfaces unless already set
     *
     * @param object $obj
     * @return self
     */
    public function register(object $obj): self
    {
        assert(!$this->locked);
        $interfaces = class_implements($obj) ?: [];
        foreach ($interfaces as $interface) {
            $this->setDefault($interface, $obj);
        }
        $parents = class_parents($obj) ?: [];
        foreach ($parents as $parent) {
            $this->setDefault($parent, $obj);
        }
        $this->values[$obj::class] = $obj;
        return $this;
    }

    /**
     * Bind an interface to a given class
     *
     * @param class-string $class
     * @param class-string $interface Optional if there is only one interface
     * @param array<mixed> $parameters
     * @return self
     */
    public function bind(string $class, ?string $interface = null, array $parameters = []): self
    {
        assert(!$this->locked);
        assert(class_exists($class), "Class `$class` does not exist");

        // If no interface is provided, binds to a single interface
        if ($interface === null) {
            $interfaces = class_implements($class) ?: [];
            assert(count($interfaces) === 1, "Class `$class` implements multiple interfaces");
            $interface = (string)key($interfaces);
        }
        assert($interface !== '' && interface_exists($interface), "Interface `$interface` does not exist");
        if (!empty($parameters)) {
            $this->parameters($class, ...$parameters);
        }
        return $this->set($interface, $class);
    }

    /**
     * Bind a class to all its interfaces if not already set
     *
     * @param class-string $class
     * @return self
     */
    public function bindAll(string $class): self
    {
        $interfaces = class_implements($class) ?: [];
        foreach ($interfaces as $interface) {
            $this->setDefault($interface, $class);
        }
        return $this;
    }

    /**
     * Define how to map constructors arguments when building objects of a given class
     *
     * @param class-string $class The class of the argument to resolve
     * @param string $key The parameter name, the class name, or * for all parameters (when using a closure)
     * @param callable|string $value fn(string $name, string $class) or a definition id
     * @return self
     */
    public function resolve(string $class, string $key, callable|string $value): self
    {
        assert(!$this->locked);
        $this->resolverRegistry->resolve($class, $key, $value);
        return $this;
    }

    /**
     * Define how to map constructors arguments when building objects of a given class
     *
     * @param class-string $class The class of the argument to resolve
     * @param callable $value fn(string $name, string $class)
     * @return self
     */
    public function resolveAll(string $class, callable $value): self
    {
        return $this->resolve($class, '*', $value);
    }

    /**
     * @param class-string $class
     * @return array<string,callable|string>
     */
    public function resolversFor(string $class): array
    {
        return $this->resolverRegistry->resolversFor($class);
    }

    /**
     * Check if we have any custom resolver that helps us to map a specific variable for a class to a registered service
     *
     * @param string $name
     * @param class-string $typeName
     * @param string $class
     * @return ?string
     */
    public function resolveName(string $name, string $typeName, string $class): ?string
    {
        return $this->resolverRegistry->resolveName($name, $typeName, $class);
    }

    /**
     * Provide a parameter for an entry
     *
     * @param string $id
     * @param string $name
     * @param mixed $value The actual value of the parameter. Pass a Closure to receive the container and resolve services lazily: fn($container) => $container->get('serviceName')
     * @return self
     */
    public function parameter(string $id, string $name, mixed $value): self
    {
        assert(!$this->locked);
        $this->parameters[$id][$name] = $value;
        return $this;
    }

    /**
     * Provide a list of parameters for an entry
     * Used with named params, eg: parameters(Xyz::class, param1: 'somevalue', param2: 'someotherval')
     */
    public function parameters(string $id, mixed ...$params): self
    {
        assert(!$this->locked);
        foreach ($params as $k => $v) {
            // casting only necessary due to mixed
            $this->parameter($id, (string)$k, $v);
        }
        return $this;
    }

    /**
     * Retrieve parameters for an entry
     *
     * @param ?string $id
     * @return array<string,mixed>
     */
    public function parametersFor(?string $id = null): array
    {
        if ($id === null) {
            return [];
        }
        return $this->parameters[$id] ?? [];
    }

    /**
     * Retrieve parameters for an entry and its base class
     *
     * @param class-string $class
     * @param ?string $id
     * @return array<string,mixed>
     */
    public function allParametersFor(string $class, ?string $id = null): array
    {
        return [...$this->parametersFor($class), ...$this->parametersFor($id)];
    }

    /**
     * Provide a callback to be applied after an entry has been instantiated
     *
     * @param string $id
     * @param Closure $fn
     * @param string|null $name A custom name for this callback. It can therefore be overwritten.
     * @return self
     */
    public function callback(string $id, Closure $fn, ?string $name = null): self
    {
        assert(!$this->locked);

        // If no name is provided, simply increment
        if ($name === null) {
            $name = (string)count($this->callbacksFor($id));
        }
        $this->callbacks[$id][$name] = $fn;
        return $this;
    }

    /**
     * Retrieve callbacks for an entry
     *
     * @param string $id
     * @return array<string,callable>
     */
    public function callbacksFor(string $id): array
    {
        return $this->callbacks[$id] ?? [];
    }

    /**
     * Retrieve callbacks for a class and all its ancestors
     *
     * @param class-string $class
     * @return list<callable>
     */
    public function callbacksForClass(string $class): array
    {
        assert(class_exists($class));

        $hierarchy = RuntimeCache::classHierarchy($class);
        $allCallbacks = [];

        // 1. Interfaces (already sorted alphabetically by RuntimeCache)
        foreach ($hierarchy['interfaces'] as $interface) {
            $allCallbacks[] = array_values($this->callbacksFor($interface));
        }
        // 2. Parents (top-to-bottom)
        foreach (array_reverse($hierarchy['parents']) as $parent) {
            $allCallbacks[] = array_values($this->callbacksFor($parent));
        }
        // 3. Concrete class
        $allCallbacks[] = array_values($this->callbacksFor($class));

        return array_merge(...$allCallbacks);
    }

    /**
     * Nicely close up definitions, since most IDE wants the ; on the same line
     * Eg:
     * $this->definitions()
     *   ->...
     *   ->lock();
     * Don't allow further edit once called (soft checks, not strictly enforced)
     *
     * @return self
     */
    public function lock(): self
    {
        $this->locked = true;
        return $this;
    }

    public function unlock(): self
    {
        $this->locked = false;
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }
}
