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
 *
 * Read API: has(), get(), getAliases(), parametersFor() and callbacksFor().
 * The raw storage accessors (getValues(), getParameters(), getCallbacks()),
 * the runtime helpers used by the Container
 * (hasAlias(), getAlias(), allParametersFor(), callbacksForClass(),
 * conventionCandidates()) and expand() are internal plumbing; they are not
 * part of the supported public API.
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
     * Alias map: alias id => target id.
     *
     * An alias delegates resolution to its target: get(alias) returns
     * get(target), configured once. Aliases cannot carry parameters or
     * callbacks of their own.
     *
     * @var array<string,string>
     */
    private array $aliases = [];

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
     * Naming conventions, in registration order.
     *
     * Conventions never create explicit definitions: they are resolved on
     * demand for interfaces with no explicit value or alias.
     *
     * @var list<ConventionRule>
     */
    private array $conventions = [];

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
        $this->assertNotLocked();

        $incoming = $definitions->getValues();
        $incomingAliases = $definitions->getAliases();

        $currentIds = array_fill_keys([...array_keys($this->values), ...array_keys($this->aliases)], true);
        $incomingIds = [...array_keys($incoming), ...array_keys($incomingAliases)];
        $collisions = array_values(array_filter($incomingIds, static fn(string $id): bool => array_key_exists(
            $id,
            $currentIds,
        )));

        if ($collisions !== []) {
            sort($collisions);
            $list = '- ' . implode("\n- ", $collisions);
            throw new DefinitionException(
                "Cannot merge definitions because the following service ids are already defined (use rebind() for intentional replacements):\n{$list}",
            );
        }

        // Conventions merge additively too: an identical source template on
        // both sides owns the same decision. Overlapping (but distinct)
        // templates are allowed; a real ambiguity fails at resolution.
        $currentFroms = array_map(static fn(ConventionRule $rule): string => $rule->from, $this->conventions);
        $duplicateFroms = [];
        foreach ($definitions->conventions as $rule) {
            if (in_array($rule->from, $currentFroms, true)) {
                $duplicateFroms[] = $rule->describe();
            }
        }
        if ($duplicateFroms !== []) {
            sort($duplicateFroms);
            $list = implode("\n", array_map(static fn(string $rule): string => '- ' . $rule, $duplicateFroms));
            throw new DefinitionException(
                "Cannot merge definitions: the following conventions are already defined:\n{$list}\n"
                . 'Merge is additive and never overrides conventions.',
            );
        }

        // Build the whole result first: a failed merge must leave this object untouched.
        $values = array_replace($this->values, $incoming);
        $aliases = array_replace($this->aliases, $incomingAliases);
        $conventions = [...$this->conventions, ...$definitions->conventions];

        $callbacks = $this->callbacks;
        foreach ($definitions->getCallbacks() as $key => $entries) {
            $callbacks[$key] = array_replace($callbacks[$key] ?? [], $entries);
        }

        $parameters = $this->parameters;
        foreach ($definitions->getParameters() as $key => $entries) {
            $parameters[$key] = array_replace($parameters[$key] ?? [], $entries);
        }

        AliasValidator::assertValid($values, $aliases, $parameters, $callbacks);

        $this->values = $values;
        $this->aliases = $aliases;
        $this->conventions = $conventions;
        $this->callbacks = $callbacks;
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * @internal Raw definition map used by merge() and the Container.
     * @return array<string,class-string|object>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @internal Raw callback map used by merge().
     * @return array<string,array<string|int,Closure>>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @internal Raw parameter map used by merge().
     * @return array<string,array<string,mixed>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Create a container from these definitions.
     *
     * This is a terminal method in the fluent chain. The Container constructor
     * locks the definitions, so they cannot be modified further afterwards.
     */
    public function createContainer(): Container
    {
        return new Container($this);
    }

    /**
     * Check if an id has an explicit definition or is an alias
     */
    public function has(string $id): bool
    {
        $id = DefinitionGuard::normalizeId($id);
        return array_key_exists($id, $this->values) || array_key_exists($id, $this->aliases);
    }

    /**
     * Get an entry (null when there is no explicit definition)
     *
     * @return class-string|object|null
     */
    public function get(string $id): string|object|null
    {
        $id = DefinitionGuard::normalizeId($id);
        return $this->values[$id] ?? null;
    }

    /**
     * Similar to get, but expand any lazy closure using the container.
     *
     * @internal Runtime helper used by Container::build().
     * @return string|object|null
     */
    public function expand(string $id, ContainerInterface $container): string|object|null
    {
        $id = DefinitionGuard::normalizeId($id);
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
        $this->assertNotLocked();
        $id = self::writableId($id);
        DefinitionGuard::assertNotDefined($this->values, $this->aliases, $id);
        DefinitionGuard::assertUsableId($id);
        DefinitionGuard::assertValidDefinition($id, $value);
        assert(
            DefinitionGuard::isCompatibleReplacement($id, $value),
            DefinitionGuard::replacementMismatch($id, $value),
        );
        $this->values[$id] = $value;
        return $this;
    }

    /**
     * Bind an interface or abstract class to a concrete class
     *
     * $concrete is built as a separate instance: a factory defined for
     * $concrete is ignored. Use alias() to share the same instance.
     *
     * @param class-string $abstract
     * @param class-string $concrete
     * @param array<string,mixed> $parameters Constructor arguments configured for $abstract
     */
    public function bind(string $abstract, string $concrete, array $parameters = []): self
    {
        $this->assertNotLocked();
        $abstract = self::writableId($abstract);
        DefinitionGuard::assertNotDefined($this->values, $this->aliases, $abstract);
        DefinitionGuard::assertUsableId($abstract);
        assert(interface_exists($abstract) || class_exists($abstract), "Abstraction `{$abstract}` does not exist");
        assert(class_exists($concrete), "Class `{$concrete}` does not exist");
        assert(is_a($concrete, $abstract, true), "Class `{$concrete}` does not implement `{$abstract}`");
        $this->values[$abstract] = $concrete;
        foreach ($parameters as $name => $value) {
            $this->parameter($abstract, (string) $name, $value);
        }
        return $this;
    }

    /**
     * Declare a naming convention for interfaces, resolved on demand.
     *
     * Only the `{module}` (one or more namespace segments) and `{name}` (a
     * single segment) placeholders are allowed. `{name}` is mandatory in the
     * source template, each variable is captured at most once, and the target
     * may only reuse captured variables:
     *
     * ```php
     * $definitions->bindConvention(
     *     'App\\{module}\\Domain\\{name}',
     *     'App\\{module}\\Infrastructure\\Doctrine{name}',
     * );
     * ```
     *
     * A convention never creates an explicit definition: an explicit `bind()`
     * (or `set()`/`alias()`) for the same interface wins naturally, and no
     * `rebind()` is needed to override it. Overlapping conventions are
     * allowed at registration; when several match the same requested
     * interface, resolution fails instead of picking one. Only existing
     * interfaces are eligible, and only at resolution time.
     */
    public function bindConvention(string $from, string $to): self
    {
        $this->assertNotLocked();
        $rule = ConventionRule::parse($from, $to);
        foreach ($this->conventions as $existing) {
            if ($existing->from === $rule->from) {
                throw new DefinitionException(
                    "Convention `{$rule->from}` is already defined. Overlapping conventions are allowed, but the same source template cannot be declared twice.",
                );
            }
        }
        $this->conventions[] = $rule;
        return $this;
    }

    /**
     * Candidate implementations for a canonical id, in registration order.
     *
     * Pure string transformation: never autoloads, never reflects, never
     * caches misses. The caller decides when an id is eligible (only existing
     * interfaces) and how to report zero, one or several matches.
     *
     * @param class-string $id
     * @return list<array{candidate:string,from:string,to:string}>
     *
     * @internal
     */
    public function conventionCandidates(string $id): array
    {
        $id = DefinitionGuard::normalizeId($id);
        $matches = [];
        foreach ($this->conventions as $rule) {
            $candidate = $rule->candidateFor($id);
            if ($candidate !== null) {
                $matches[] = [
                    'candidate' => $candidate,
                    'from' => $rule->from,
                    'to' => $rule->to,
                ];
            }
        }
        return $matches;
    }

    /**
     * Make an id an alias of an existing definition.
     *
     * get(alias) returns get(target): the same shared instance, configured
     * once. This is the explicit alternative to a factory that returns another
     * entry, which would run the target's callbacks a second time for the alias
     * id.
     *
     * The target must already be defined (a value or another alias). Aliases
     * cannot carry their own parameters or callbacks, and alias cycles are
     * rejected.
     */
    public function alias(string $alias, string $target): self
    {
        $this->assertNotLocked();
        $alias = self::writableId($alias);
        $target = self::writableId($target);
        DefinitionGuard::assertUsableId($alias);
        DefinitionGuard::assertNotDefined($this->values, $this->aliases, $alias);

        if (!array_key_exists($target, $this->values) && !array_key_exists($target, $this->aliases)) {
            throw new DefinitionException(
                "Cannot alias `{$alias}` to `{$target}`: no existing definition was found. Define it first with set() or bind().",
            );
        }
        AliasValidator::assertHasNoOwnConfiguration($alias, $this->parameters, $this->callbacks);
        if (AliasValidator::wouldCycle($this->aliases, $alias, $target)) {
            throw new DefinitionException("Cannot alias `{$alias}` to `{$target}`: that would create an alias cycle.");
        }

        $this->aliases[$alias] = $target;
        return $this;
    }

    /**
     * @return array<string,string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * @internal Runtime helper used by the Container.
     */
    public function hasAlias(string $id): bool
    {
        $id = DefinitionGuard::normalizeId($id);
        return array_key_exists($id, $this->aliases);
    }

    /**
     * @internal Runtime helper used by the Container.
     * Target of an alias, or null when the id is not an alias.
     */
    public function getAlias(string $id): ?string
    {
        $id = DefinitionGuard::normalizeId($id);
        return $this->aliases[$id] ?? null;
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
     * When the id names a class or an interface, the value is checked
     * statically whenever its actual type is knowable without running
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
     * @param bool $resetConfiguration Clear constructor parameters and callbacks registered under $id
     */
    public function rebind(
        string $id,
        string|object $value,
        string|object|null $expected = null,
        bool $resetConfiguration = false,
    ): self {
        $this->assertNotLocked();
        $id = self::writableId($id);
        if (!array_key_exists($id, $this->values)) {
            throw new DefinitionException(
                "Cannot rebind `{$id}`: no existing definition was found. Define it first with set() or bind().",
            );
        }
        if ($expected !== null && $this->values[$id] !== $expected) {
            $current = DefinitionGuard::describeValue($this->values[$id]);
            $wanted = DefinitionGuard::describeValue($expected);
            throw new DefinitionException(
                "Cannot rebind `{$id}`: expected `{$wanted}`, currently defined as `{$current}`.",
            );
        }
        DefinitionGuard::assertUsableId($id);
        DefinitionGuard::assertValidDefinition($id, $value);

        // Development-only: is a class id bound to a compatible value? The whole
        // check lives inside assert() so it never autoloads in production.
        assert(
            DefinitionGuard::isCompatibleReplacement($id, $value),
            DefinitionGuard::replacementMismatch($id, $value),
        );

        $this->values[$id] = $value;
        if ($resetConfiguration) {
            unset($this->parameters[$id], $this->callbacks[$id]);
        }
        return $this;
    }

    /**
     * Provide a parameter for an entry
     *
     * @param mixed $value Literal value or Closure(ContainerInterface) to resolve lazily
     */
    public function parameter(string $id, string $name, mixed $value): self
    {
        $this->assertNotLocked();
        $id = self::writableId($id);
        DefinitionGuard::assertNotAlias($this->aliases, $id);
        $this->parameters[$id][$name] = $value;
        return $this;
    }

    /**
     * Provide a list of parameters for an entry
     * Used with named params, eg: parameters(Xyz::class, param1: 'somevalue', param2: 'someotherval')
     */
    public function parameters(string $id, mixed ...$params): self
    {
        $this->assertNotLocked();
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
        $id = DefinitionGuard::normalizeId($id);
        return $this->parameters[$id] ?? [];
    }

    /**
     * Retrieve parameters for a class, its binding target and the requested id
     * (id wins)
     *
     * @internal Runtime helper used by Container::build().
     *
     * $target is the binding target as the definition wrote it. When it spells
     * the concrete class differently (a class_alias() target, a case variant),
     * parameters configured under that spelling still apply instead of silently
     * falling back to the constructor default.
     *
     * @param class-string $class The declared name of the concrete class
     * @param string $id The service id being built
     * @param string|null $target The binding target as written, when it differs from $class
     * @return array<string,mixed>
     */
    public function allParametersFor(string $class, string $id, ?string $target = null): array
    {
        return DefinitionGuard::mergeConfiguredParameters($this->parameters, $class, $id, $target);
    }

    /**
     * Provide a callback to be applied after an entry has been instantiated
     *
     * @param string|null $name A custom name for this callback. It can therefore be overwritten.
     */
    public function callback(string $id, Closure $fn, ?string $name = null): self
    {
        $this->assertNotLocked();
        $id = self::writableId($id);
        DefinitionGuard::assertNotAlias($this->aliases, $id);
        // Use a stable key so merging definitions never renumbers callbacks. The
        // prefix keeps it apart from explicit names such as "5".
        $name ??= '#' . spl_object_id($fn);
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
        $id = DefinitionGuard::normalizeId($id);
        return $this->callbacks[$id] ?? [];
    }

    /**
     * Retrieve callbacks for a class and all its ancestors
     *
     * Order: interfaces (alphabetically), then parents (top to bottom), then the class itself.
     *
     * @internal Runtime helper used by Container::configure().
     * @param class-string $class
     * @return list<callable>
     */
    public function callbacksForClass(string $class): array
    {
        // Nothing to look up: skip the hierarchy entirely.
        if ($this->callbacks === []) {
            return [];
        }

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

    /**
     * Normalize an id before storing anything under it. A class id must be
     * written as declared: checked in development only, since it may autoload.
     */
    private static function writableId(string $id): string
    {
        $id = DefinitionGuard::normalizeId($id);
        assert(
            DefinitionGuard::isCanonicalId($id),
            "Id `{$id}` names a class with a different case: use its declared name (`Foo::class`).",
        );
        return $id;
    }

    private function assertNotLocked(): void
    {
        if ($this->locked) {
            throw new DefinitionException('Definitions are locked and cannot be modified.');
        }
    }
}
