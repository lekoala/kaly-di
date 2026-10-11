<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionParameter;

/**
 * Caches immutable reflection metadata for the duration of the process.
 *
 * Only facts that cannot change while the process runs are cached: a class's
 * constructor signature and its class hierarchy. Class existence is
 * deliberately NOT cached, so a negative lookup can never become stale.
 *
 * For stateless, dependency-free helpers (class names, parameter class),
 * see {@see Reflection} (public API).
 *
 * @internal
 */
final class ReflectionCache
{
    /**
     * @var array<class-string, array{ReflectionClass<object>, ReflectionParameter[]}>
     */
    private static array $reflection = [];

    /**
     * @var array<class-string, array{interfaces: list<class-string>, parents: list<class-string>}>
     */
    private static array $hierarchy = [];

    /**
     * @var array<string, class-string>
     */
    private static array $names = [];

    /**
     * The declared name of a class or interface, as PHP resolves it
     * (case-insensitive, leading backslash ignored), or null when it does not
     * exist. Only positive lookups are cached: a loaded class cannot vanish.
     *
     * @return class-string|null
     */
    public static function canonicalName(string $name): ?string
    {
        if (array_key_exists($name, self::$names)) {
            return self::$names[$name];
        }
        $trimmed = ltrim($name, '\\');
        if (!class_exists($trimmed) && !interface_exists($trimmed)) {
            return null;
        }
        /** @var class-string $canonical */
        $canonical = (new ReflectionClass($trimmed))->getName();
        return self::$names[$name] = $canonical;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return array{ReflectionClass<T>, ReflectionParameter[]}
     * @throws \ReflectionException When the class does not exist
     */
    public static function reflection(string $class): array
    {
        if (!array_key_exists($class, self::$reflection)) {
            $refl = new ReflectionClass($class);
            $constructor = $refl->getConstructor();
            $params = $constructor ? $constructor->getParameters() : [];
            self::$reflection[$class] = [$refl, $params];
        }

        /** @var array{ReflectionClass<T>, ReflectionParameter[]} */
        return self::$reflection[$class];
    }

    /**
     * Get class hierarchy (interfaces and parent classes)
     *
     * @param class-string $class
     * @return array{interfaces: list<class-string>, parents: list<class-string>}
     */
    public static function classHierarchy(string $class): array
    {
        // Never cache negative lookups: unknown names always resolve to empty
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return ['interfaces' => [], 'parents' => []];
        }
        if (!array_key_exists($class, self::$hierarchy)) {
            /** @var array<class-string, class-string>|false $interfacesRaw */
            $interfacesRaw = class_implements($class);
            $interfaces = is_array($interfacesRaw) ? $interfacesRaw : [];
            ksort($interfaces); // deterministic order
            /** @var array<class-string, class-string>|false $parentsRaw */
            $parentsRaw = class_parents($class);
            $parents = is_array($parentsRaw) ? $parentsRaw : [];
            self::$hierarchy[$class] = ['interfaces' => array_values($interfaces), 'parents' => array_values($parents)];
        }

        return self::$hierarchy[$class];
    }

    /**
     * Check whether a class can be instantiated.
     *
     * Returns false for interfaces, abstract classes and enums. Existence is
     * checked on every call (never cached), only the reflection is cached.
     */
    public static function isInstantiable(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return self::reflection($class)[0]->isInstantiable();
    }

    /**
     * Clear all caches (useful for testing)
     */
    public static function clear(): void
    {
        self::$reflection = [];
        self::$hierarchy = [];
        self::$names = [];
    }
}
