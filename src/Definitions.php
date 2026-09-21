<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Fluent builder for container configuration.
 *
 * Maps identifiers (class names, interfaces, or custom strings) to class names,
 * objects, or factory closures. Also manages constructor parameters and
 * post-construction callbacks.
 *
 * The primitives are orthogonal: each method does exactly one thing.
 *
 * Configuration state such as locking is enforced at runtime, while
 * development-time validity checks use assert() where appropriate.
 */
final class Definitions
{
    /**
     * Store definitions as a map.
     * Typically, the key is a class name or a custom id.
     * Class strings are resolved to a class instance while objects are returned as is.
     * If the value is a Closure, it is executed as a lazy factory (see expand).
     *
     * @var array<string,class-string|object>
     */
    private array $values = [];

    /**
     * Defines callbacks to be called after an object is instantiated
     *
     * @var array<string,array<string|int,Closure>>
     */
    private array $callbacks = [];

    /**
     * Defines parameters passed to a given id
     *
     * @var array<string,array<string,mixed>>
     */
    private array $parameters = [];

    /**
     * Lock status
     */
    private bool $locked = false;

    /**
     * You can create the definitions with a basic array that maps ids to a class name, an object or a closure.
     *
     * @param array<string,class-string|object> $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $id => $value) {
            $this->set((string) $id, $value);
        }
    }

    /**
     * Pre PHP 8.4 helper for a better syntax
     *
     * @param array<string,class-string|object> $definitions
     */
    public static function create(array $definitions = []): self
    {
        return new self($definitions);
    }

    /**
     * Merge another Definitions object into this one. Later values win.
     */
    public function merge(Definitions $definitions): void
    {
        $this->ensureNotLocked();

        $this->values = array_replace($this->values, $definitions->getValues());

        foreach ($definitions->getCallbacks() as $key => $values) {
            $this->callbacks[$key] = array_replace($this->callbacks[$key] ?? [], $values);
        }

        foreach ($definitions->getParameters() as $key => $values) {
            $this->parameters[$key] = array_replace($this->parameters[$key] ?? [], $values);
        }
    }

    /**
     * @return array<string,class-string|object>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return array<string,array<string|int,Closure>>
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
     * Create a container from these definitions and lock them.
     *
     * This is a terminal method in the fluent chain. After calling it,
     * the definitions are locked and cannot be modified further.
     */
    public function createContainer(): Container
    {
        $this->lock();
        return new Container($this);
    }

    /**
     * Check if an id has an explicit definition
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->values);
    }

    /**
     * Get an entry (null when there is no explicit definition)
     *
     * @return class-string|object|null
     */
    public function get(string $id): string|object|null
    {
        return $this->values[$id] ?? null;
    }

    /**
     * Similar to get, but expand any lazy closure using the container.
     *
     * @return string|object|null
     */
    public function expand(string $id, ContainerInterface $container): string|object|null
    {
        $entry = $this->values[$id] ?? null;
        // If we have a closure, run it to get a string or an object
        if ($entry instanceof Closure) {
            $entry = $entry($container);
            assert(is_object($entry) || is_string($entry));
        }
        return $entry;
    }

    /**
     * Add an entry
     *
     * @param class-string|object $value
     */
    public function set(string $id, string|object $value): self
    {
        $this->ensureNotLocked();
        assert(is_object($value) || class_exists($value), "Value for `{$id}` is not valid");
        // Avoid resolving stdClass with the DI container
        assert($id !== \stdClass::class, 'Cannot set stdClass as id');
        $this->values[$id] = $value;
        return $this;
    }

    /**
     * Bind an interface or abstract class to a concrete class
     *
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function bind(string $abstract, string $concrete): self
    {
        $this->ensureNotLocked();
        assert(interface_exists($abstract) || class_exists($abstract), "Abstraction `{$abstract}` does not exist");
        assert(class_exists($concrete), "Class `{$concrete}` does not exist");
        assert(is_a($concrete, $abstract, true), "Class `{$concrete}` does not implement `{$abstract}`");
        $this->values[$abstract] = $concrete;
        return $this;
    }

    /**
     * Provide a parameter for an entry
     *
     * @param mixed $value Literal value or Closure(ContainerInterface) to resolve lazily
     */
    public function parameter(string $id, string $name, mixed $value): self
    {
        $this->ensureNotLocked();
        $this->parameters[$id][$name] = $value;
        return $this;
    }

    /**
     * Provide a list of parameters for an entry
     * Used with named params, eg: parameters(Xyz::class, param1: 'somevalue', param2: 'someotherval')
     */
    public function parameters(string $id, mixed ...$params): self
    {
        $this->ensureNotLocked();
        foreach ($params as $k => $v) {
            $this->parameter($id, (string) $k, $v);
        }
        return $this;
    }

    /**
     * Retrieve parameters for an entry
     *
     * @return array<string,mixed>
     */
    public function parametersFor(string $id): array
    {
        return $this->parameters[$id] ?? [];
    }

    /**
     * Retrieve parameters for a class and for the requested id (id wins)
     *
     * @param class-string $class
     * @return array<string,mixed>
     */
    public function allParametersFor(string $class, string $id): array
    {
        if ($class === $id) {
            return $this->parameters[$class] ?? [];
        }
        return array_replace($this->parameters[$class] ?? [], $this->parameters[$id] ?? []);
    }

    /**
     * Provide a callback to be applied after an entry has been instantiated
     *
     * @param string|null $name A custom name for this callback. It can therefore be overwritten.
     */
    public function callback(string $id, Closure $fn, ?string $name = null): self
    {
        $this->ensureNotLocked();
        // Use a stable, collision-free key so merging definitions never renumbers callbacks
        $name ??= (string) spl_object_id($fn);
        $this->callbacks[$id][$name] = $fn;
        return $this;
    }

    /**
     * Retrieve callbacks for an entry
     *
     * @return array<string|int,Closure>
     */
    public function callbacksFor(string $id): array
    {
        return $this->callbacks[$id] ?? [];
    }

    /**
     * Retrieve callbacks for a class and all its ancestors
     *
     * Order: interfaces (alphabetically), then parents (top to bottom), then the class itself.
     *
     * @param class-string $class
     * @return list<callable>
     */
    public function callbacksForClass(string $class): array
    {
        assert(class_exists($class));

        $hierarchy = ReflectionCache::classHierarchy($class);
        $allCallbacks = [];

        // 1. Interfaces (already sorted alphabetically by ReflectionCache)
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
     * Prevent further modifications
     */
    public function lock(): self
    {
        $this->locked = true;
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    private function ensureNotLocked(): void
    {
        if ($this->locked) {
            throw new LogicException('Definitions are locked and cannot be modified.');
        }
    }
}
