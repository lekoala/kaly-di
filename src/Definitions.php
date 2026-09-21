<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
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
 * Invalid configuration is reported in two ways, based on its cost:
 *
 * - Unconditional configuration invariants (locking, duplicate ids, merge
 *   collisions, rebind preconditions, illegal factory results) always throw a
 *   DefinitionException. They are already known and cheap to check, and must
 *   behave identically whether or not assertions are enabled.
 * - Checks that may autoload or reflect code the runtime might never use (class
 *   existence, binding compatibility) use assert(). They give immediate
 *   development feedback without forcing production to visit unused services.
 *
 * The real validation of a composition is its test suite: building each real
 * configuration and resolving its real entry points exercises every visited
 * path. Kaly deliberately does not audit the whole graph ahead of time.
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
     * Merge another Definitions object into this one.
     *
     * Merging is additive: service definitions are never implicitly replaced.
     * Any service id defined on both sides is a conflict, even when both sides
     * declare the exact same value, because two modules would own the same
     * decision. Conflicts are reported before anything is mutated, so a failed
     * merge leaves this object completely unchanged.
     *
     * Parameters and callbacks keep their existing merge semantics (later
     * values win per key), since they customize a definition rather than
     * choosing which implementation owns a service.
     */
    public function merge(Definitions $definitions): self
    {
        $this->ensureNotLocked();

        $incoming = $definitions->getValues();
        $collisions = array_keys(array_intersect_key($this->values, $incoming));

        if ($collisions !== []) {
            sort($collisions);
            $list = implode("\n", array_map(static fn(string $id): string => "- {$id}", $collisions));
            throw new DefinitionException(
                "Cannot merge definitions: the following service ids are already defined:\n{$list}\n"
                . 'Merge is additive and never overrides services. '
                . 'Use rebind() explicitly for intentional replacements.',
            );
        }

        $this->values = array_replace($this->values, $incoming);

        foreach ($definitions->getCallbacks() as $key => $values) {
            $this->callbacks[$key] = array_replace($this->callbacks[$key] ?? [], $values);
        }

        foreach ($definitions->getParameters() as $key => $values) {
            $this->parameters[$key] = array_replace($this->parameters[$key] ?? [], $values);
        }

        return $this;
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
            if (!is_object($entry) && !is_string($entry)) {
                throw new DefinitionException(
                    "The factory registered for `{$id}` must return an object or a class-string, "
                    . 'got '
                    . get_debug_type($entry)
                    . '.',
                );
            }
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
        $this->ensureNotDefined($id);
        $this->ensureUsableId($id);
        $this->assertValidDefinition($id, $value);
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
        $this->ensureNotDefined($abstract);
        assert(interface_exists($abstract) || class_exists($abstract), "Abstraction `{$abstract}` does not exist");
        assert(class_exists($concrete), "Class `{$concrete}` does not exist");
        assert(is_a($concrete, $abstract, true), "Class `{$concrete}` does not implement `{$abstract}`");
        $this->values[$abstract] = $concrete;
        return $this;
    }

    /**
     * Intentionally replace an existing service definition.
     *
     * This is the only operation that replaces a service, and it requires the
     * id to already be defined. Unlike merge(), which is additive and fails on
     * conflicts, rebind() expresses a deliberate substitution: an alternate
     * composition for a test, a demo, or a separate runtime.
     *
     * It accepts the same value shapes as set() (class-string, object, closure).
     * When the id is typed (an interface or an abstract class), the value is
     * checked statically whenever its actual type is knowable without running
     * user code: class-strings and concrete objects must be compatible, while
     * closures stay free because their result is only known at execution time.
     *
     * An optional `expected` precondition turns rebind() into a
     * compare-and-swap: the replacement only happens when the id is still
     * defined exactly as the caller assumed. The comparison is strict identity
     * (`!==`), never structural equality. This guards against stale overrides:
     * when the main composition evolves, a test, demo, mock or CLI variant that
     * relied on an old implementation fails immediately instead of silently
     * keeping a wrong assumption.
     *
     * ```php
     * $definitions->rebind(
     *     StorageInterface::class,
     *     InMemoryStorage::class,
     *     expected: DatabaseStorage::class,
     * );
     * ```
     *
     * rebind() changes the service for the whole container. For a single
     * consumer that needs a different dependency in the same container,
     * configure that consumer explicitly instead.
     *
     * @param class-string|object $value
     * @param class-string|object|null $expected Guard: the id must currently be defined as this exact value
     */
    public function rebind(string $id, string|object $value, string|object|null $expected = null): self
    {
        $this->ensureNotLocked();
        if (!array_key_exists($id, $this->values)) {
            throw new DefinitionException(
                "Cannot rebind `{$id}`: no existing definition was found. Define it first with set() or bind().",
            );
        }
        if ($expected !== null && $this->values[$id] !== $expected) {
            $current = $this->describeValue($this->values[$id]);
            $wanted = $this->describeValue($expected);
            throw new DefinitionException(
                "Cannot rebind `{$id}`: expected `{$wanted}`, currently defined as `{$current}`.",
            );
        }
        $this->ensureUsableId($id);
        $this->assertValidDefinition($id, $value);

        if (interface_exists($id) || class_exists($id) && (new \ReflectionClass($id))->isAbstract()) {
            if (is_string($value)) {
                assert(is_a($value, $id, true), "Class `{$value}` does not implement `{$id}`");
            } elseif (!$value instanceof Closure) {
                $valueClass = $value::class;
                assert($value instanceof $id, "Object `{$valueClass}` does not implement `{$id}`");
            }
        }

        $this->values[$id] = $value;
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
            throw new DefinitionException('Definitions are locked and cannot be modified.');
        }
    }

    /**
     * Service definitions are additive: a given id can only be owned once.
     */
    private function ensureNotDefined(string $id): void
    {
        if (array_key_exists($id, $this->values)) {
            throw new DefinitionException(
                "Service `{$id}` is already defined. Use rebind() if replacing it is intentional.",
            );
        }
    }

    /**
     * Reject ids that would collide with a resolved stdClass value.
     *
     * A cheap, unconditional configuration invariant: no autoloading or
     * reflection is required.
     */
    private function ensureUsableId(string $id): void
    {
        if ($id === \stdClass::class) {
            throw new DefinitionException('Cannot set stdClass as id');
        }
    }

    /**
     * Development assertion: the value is an object or an existing class name.
     *
     * class_exists() may autoload the class, so this check must not run in
     * production for services the runtime never visits. assertValidDefinition
     * is therefore an assertion, not a runtime guarantee.
     *
     * @param class-string|object $value
     */
    private function assertValidDefinition(string $id, string|object $value): void
    {
        assert(is_object($value) || class_exists($value), "Value for `{$id}` is not valid");
    }

    /**
     * Human-readable description of a definition value for error messages.
     *
     * Objects and closures include their instance id so that two distinct
     * instances of the same class are not rendered identically.
     *
     * @param class-string|object $value
     */
    private function describeValue(string|object $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof Closure) {
            return 'Closure#' . spl_object_id($value);
        }
        return $value::class . '#' . spl_object_id($value);
    }
}
