<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Reflection helpers without container dependency.
 *
 * Unlike Parameters (internal resolution engine), these methods never read
 * definitions, never throw container exceptions and are safe to use anywhere.
 *
 * tryClass() shares the process-duration cache used by the container
 * ({@see ReflectionCache}, internal); the other helpers are stateless.
 *
 * @api
 */
final class Reflection
{
    /**
     * The reflection of an existing class, or null when it does not exist.
     *
     * Interfaces and traits are not classes and return null; enums are
     * classes. Successful lookups are cached for the duration of the process,
     * once per class whatever the spelling of `$class` (class names are
     * case-insensitive), so names from user input cannot grow the cache.
     * Failed lookups are never cached: a class loaded later is found.
     * Exceptions thrown by an autoloader propagate: null means "no such
     * class", not "loading failed".
     *
     * @return ReflectionClass<object>|null
     */
    public static function tryClass(string $class): ?ReflectionClass
    {
        return ReflectionCache::tryReflection($class);
    }

    /**
     * The first non-builtin named type of a parameter, without instantiating
     * a ReflectionClass.
     *
     * On a union, the first non-builtin named type wins. An intersection has
     * no single class, so null is returned. `self` and `parent` are resolved
     * against the declaring class.
     *
     * @return class-string|null
     */
    public static function getParameterTypeName(ReflectionParameter $param): ?string
    {
        foreach (Parameters::getParameterTypes($param) as $type) {
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                return TypeMatcher::className($type, $param->getDeclaringClass());
            }
        }
        return null;
    }

    /**
     * Resolve the class of a parameter, skipping builtin types.
     *
     * On a union, the first non-builtin named type wins. An intersection has
     * no single class, so null is returned.
     *
     * See https://php.watch/versions/8.0/deprecated-reflectionparameter-methods#getClass.
     *
     * @return ReflectionClass<object>|null
     */
    public static function getParameterClass(ReflectionParameter $param): ?ReflectionClass
    {
        $name = self::getParameterTypeName($param);
        return $name === null ? null : new ReflectionClass($name);
    }

    /**
     * Get a class name from a string or object.
     */
    public static function getClassName(string|object $class): string
    {
        if (is_object($class)) {
            $class = $class::class;
        }
        return $class;
    }

    /**
     * Get a class name without namespace.
     */
    public static function getShortClassName(string|object $class): string
    {
        $class = self::getClassName($class);
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Get a class namespace.
     *
     * @param class-string|object $class
     */
    public static function getClassNamespace(string|object $class): string
    {
        $class = self::getClassName($class);
        $pos = strrpos($class, '\\');
        return $pos === false ? '' : substr($class, 0, $pos);
    }
}
