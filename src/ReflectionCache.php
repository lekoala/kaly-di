<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionParameter;

/**
 * Caches immutable reflection metadata for the duration of the process.
 *
 * Only facts that cannot change while the process runs are cached: a class's
 * constructor signature, its class hierarchy and its declared name. Negative
 * lookups are deliberately NOT cached, so they can never become stale.
 *
 * Cache keys are normalized (lowercase, no leading backslash) because class
 * names are case-insensitive: every spelling of a class shares one entry, so
 * names coming from user input cannot grow the cache beyond the loaded
 * classes. The original name is still the one given to the autoloader, whose
 * lookup may be case-sensitive.
 *
 * For the public, dependency-free helpers, see {@see Reflection}.
 *
 * @internal
 */
final class ReflectionCache
{
    /**
     * @var array<string, array{ReflectionClass<object>, ReflectionParameter[]}>
     */
    private static array $reflection = [];

    /**
     * @var array<string, array{interfaces: list<class-string>, parents: list<class-string>}>
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
        $key = self::key($name);
        if (array_key_exists($key, self::$names)) {
            return self::$names[$key];
        }
        $trimmed = ltrim($name, '\\');
        if (!class_exists($trimmed) && !interface_exists($trimmed)) {
            return null;
        }
        /** @var class-string $canonical */
        $canonical = (new ReflectionClass($trimmed))->getName();
        return self::$names[$key] = $canonical;
    }

    /**
     * The reflection of an existing class, or null. A cache hit skips the
     * existence check; a miss is never cached. Autoloader errors propagate.
     *
     * @return ReflectionClass<object>|null
     */
    public static function tryReflection(string $class): ?ReflectionClass
    {
        $cached = self::$reflection[self::key($class)] ?? null;
        if ($cached !== null) {
            return $cached[0];
        }
        if (!class_exists($class)) {
            return null;
        }
        return self::reflection($class)[0];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return array{ReflectionClass<T>, ReflectionParameter[]}
     * @throws \ReflectionException When the class does not exist
     */
    public static function reflection(string $class): array
    {
        $key = self::key($class);
        if (!array_key_exists($key, self::$reflection)) {
            $refl = new ReflectionClass($class);
            $constructor = $refl->getConstructor();
            $params = $constructor ? $constructor->getParameters() : [];
            self::$reflection[$key] = [$refl, $params];
        }

        /** @var array{ReflectionClass<T>, ReflectionParameter[]} */
        return self::$reflection[$key];
    }

    /**
     * Get class hierarchy (interfaces and parent classes)
     *
     * @param class-string $class
     * @return array{interfaces: list<class-string>, parents: list<class-string>}
     */
    public static function classHierarchy(string $class): array
    {
        $key = self::key($class);
        if (array_key_exists($key, self::$hierarchy)) {
            return self::$hierarchy[$key];
        }
        // Never cache negative lookups: unknown names always resolve to empty
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return ['interfaces' => [], 'parents' => []];
        }
        /** @var array<class-string, class-string>|false $interfacesRaw */
        $interfacesRaw = class_implements($class);
        $interfaces = is_array($interfacesRaw) ? $interfacesRaw : [];
        ksort($interfaces); // deterministic order
        /** @var array<class-string, class-string>|false $parentsRaw */
        $parentsRaw = class_parents($class);
        $parents = is_array($parentsRaw) ? $parentsRaw : [];

        return self::$hierarchy[$key] = [
            'interfaces' => array_values($interfaces),
            'parents' => array_values($parents),
        ];
    }

    /**
     * Check whether a class can be instantiated.
     *
     * Returns false for interfaces, abstract classes and enums.
     */
    public static function isInstantiable(string $class): bool
    {
        return self::tryReflection($class)?->isInstantiable() ?? false;
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

    /**
     * One cache entry per class, whatever its spelling.
     */
    private static function key(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
