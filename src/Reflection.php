<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Pure reflection helpers without container dependency.
 *
 * Unlike Parameters (internal resolution engine), these methods never read
 * definitions, never throw container exceptions and are safe to use anywhere.
 *
 * For process-duration caching of constructor signatures and class
 * hierarchies, see {@see ReflectionCache} (internal).
 *
 * @api
 */
final class Reflection
{
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
        foreach (Parameters::getParameterTypes($param) as $type) {
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $name */
                $name = $type->getName();
                return new ReflectionClass($name);
            }
        }
        return null;
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
