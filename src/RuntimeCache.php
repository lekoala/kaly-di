<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionParameter;

/**
 * Unified runtime cache to avoid executing expensive operations multiple times
 *
 * This cache is not persisted - it only lives for the duration of the request/process.
 * It's useful for long-running PHP applications where reflection and class lookups
 * would otherwise be repeated on every request.
 *
 * @internal
 */
final class RuntimeCache
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
     * @var array<string, bool>
     */
    private static array $classes = [];

    /**
     * @var array<string, bool>
     */
    private static array $types = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return array{ReflectionClass<T>, ReflectionParameter[]}
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
        if (!array_key_exists($class, self::$hierarchy)) {
            $interfacesRaw = class_implements($class);
            $interfaces = is_array($interfacesRaw) ? $interfacesRaw : [];
            ksort($interfaces); // deterministic order
            $parentsRaw = class_parents($class);
            $parents = is_array($parentsRaw) ? $parentsRaw : [];
            self::$hierarchy[$class] = ['interfaces' => array_values($interfaces), 'parents' => array_values($parents)];
        }

        return self::$hierarchy[$class];
    }

    /**
     * Check if a class exists
     */
    public static function classExists(string $class): bool
    {
        if (!array_key_exists($class, self::$classes)) {
            self::$classes[$class] = class_exists($class);
        }

        return self::$classes[$class];
    }

    /**
     * Check if a type exists (class or interface)
     */
    public static function typeExists(string $type): bool
    {
        if (!array_key_exists($type, self::$types)) {
            self::$types[$type] = class_exists($type) || interface_exists($type);
        }

        return self::$types[$type];
    }

    /**
     * Clear all caches (useful for testing)
     */
    public static function clear(): void
    {
        self::$reflection = [];
        self::$hierarchy = [];
        self::$classes = [];
        self::$types = [];
    }
}
