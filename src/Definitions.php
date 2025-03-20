<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use stdClass;

/**
 * This class is used to build definitions for the DI Container
 *
 * At its core, there is a map of id/class => class/object
 *
 * But it also allows defining parameters, callbacks after instantiation and custom
 * resolvers for specific classes or interfaces
 *
 * class_exists check are only enabled during development as DX convenience
 * see https://github.com/capsulephp/di/issues/6 for reasoning
 */
class Definitions
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
     * @var array<class-string,array<string,callable|string>>
     */
    protected array $resolvers = [];

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
        $this->values = array_merge($this->values, $definitions->getValues());

        //@phpstan-ignore-next-line
        $this->callbacks = $this->mergeDefinitionsData($this->callbacks, $definitions->getCallbacks());
        //@phpstan-ignore-next-line
        $this->parameters = $this->mergeDefinitionsData($this->parameters, $definitions->getParameters());
        //@phpstan-ignore-next-line
        $this->resolvers = $this->mergeDefinitionsData($this->resolvers, $definitions->getResolvers());
    }

    /**
     * @param array<string,array<mixed>> $arr
     * @param array<string,array<mixed>> $arr2
     * @return array<string,array<mixed>>
     */
    public function mergeDefinitionsData(array $arr, array $arr2): array
    {
        foreach ($arr2 as $key => $values) {
            $arr[$key] = array_merge($arr[$key] ?? [], $values);
        }
        return $arr;
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
        return $this->resolvers;
    }

    public function createContainer(): Container
    {
        return new Container($this);
    }

    public function sort(): void
    {
        ksort($this->values);
        ksort($this->callbacks);
        ksort($this->parameters);
        ksort($this->resolvers);
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
     * Check if entry does not exist
     *
     * @param string $id
     * @return bool
     */
    public function miss(string $id): bool
    {
        return !$this->has($id);
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
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    public function set(string $id, string|object|null $value = null): self
    {
        assert(!$this->locked);
        assert(is_null($value) || is_object($value) || class_exists($value), "Value for `$id` is not valid");
        assert($id !== stdClass::class, "Cannot set stdClass as id");
        $this->values[$id] = $value;
        return $this;
    }

    /**
     * Add an entry if not set yet
     *
     * @param string $id
     * @param class-string|object|null $value
     * @return self
     */
    public function setDefault(string $id, string|object|null $value = null): self
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
    public function add(object $obj): self
    {
        assert(!$this->locked);
        $interfaces = class_implements($obj);
        foreach ($interfaces as $interface) {
            $this->setDefault($interface, $obj);
        }
        $parents = class_parents($obj);
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
    public function bind(string $class, ?string $interface = null, $parameters = []): self
    {
        assert(!$this->locked);
        assert(class_exists($class), "Class `$class` does not exist");

        // If no interface is provide, bind to first interface
        if ($interface === null) {
            $interfaces = class_implements($class);
            assert(count($interfaces) === 1, "Class `$class` implements multiple interfaces");
            $interface = key($interfaces);
        }
        assert($interface !== null && interface_exists($interface), "Interface `$interface` does not exist");
        if (!empty($parameters)) {
            $this->parametersArray($class, $parameters);
        }
        return $this->set($interface, $class);
    }

    /**
     * Bind a class to all its interfaces if not already set
     *
     * @param class-string $class
     * @return self
     */
    public function bindAll(string $class)
    {
        $interfaces = class_implements($class);
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
        $this->resolvers[$class][$key] = $value;
        return $this;
    }

    /**
     * @param class-string $class
     * @return array<string,callable|string>
     */
    public function resolversFor(string $class): array
    {
        return $this->resolvers[$class] ?? [];
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
        $serviceName = null;
        $resolvers = $this->resolversFor($typeName);
        if (!empty($resolvers)) {
            foreach ($resolvers as $key => $value) {
                // Resolve all parameters using the closure
                if ($key === '*' && $value instanceof Closure) {
                    $serviceName = $value($name, $class);
                    break;
                }
                // Resolve a single parameter by name using the closure or set the service id
                if ($key === $name) {
                    $serviceName = $value instanceof Closure ? $value($name, $class) : $value;
                    break;
                }
                // Resolve by class/interface using the closure or set the service id
                if (str_contains((string) $key, '\\') && is_a($class, $key, true)) {
                    $serviceName = $value instanceof Closure ? $value($name, $class) : $value;
                    break;
                }
            }
        }
        assert(is_null($serviceName) || is_string($serviceName));
        return $serviceName;
    }

    /**
     * Provide a parameter for an entry
     *
     * @param string $id
     * @param string $name
     * @param mixed $value The actual value of the parameter. Cannot be a service id string, see resolvers.
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
     *
     * @param array<mixed> ...$params
     */
    public function parameters(string $id, ...$params): self
    {
        assert(!$this->locked);
        foreach ($params as $k => $v) {
            // casting only necessary due to mixed
            $this->parameter($id, (string)$k, $v);
        }
        return $this;
    }


    /**
     * Provide a list of parameters for an entry
     * Used with array, eg: parametersArray(Xyz::class, ['param1' => 'somevalue', 'param2' => 'someotherval'])
     *
     * @param array<mixed> $params
     */
    public function parametersArray(string $id, array $params): self
    {
        return $this->parameters($id, ...$params);
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
        return array_merge($this->parametersFor($class), $this->parametersFor($id));
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
     * @return list<callable(): mixed>
     */
    public function callbacksForClass(string $class): array
    {
        assert(class_exists($class));
        $parents = class_parents($class);
        $callbacks = [];
        // Use array_values to avoid callbacks being overwritten since they could share the same index
        foreach ($parents as $parent) {
            $callbacks = array_merge($callbacks, array_values($this->callbacksFor($parent)));
        }
        $callbacks = array_merge($callbacks, array_values($this->callbacksFor($class)));
        return $callbacks;
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
