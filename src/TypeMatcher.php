<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Checks value compatibility against potentially complex PHP type hints
 * (nullable, union, intersection, class/interface, built-in).
 *
 * Keeping this out of Parameters keeps that class below the complexity
 * threshold; it is an implementation detail.
 *
 * @internal
 */
final class TypeMatcher
{
    /**
     * @param mixed $value
     * @param ReflectionType|null $type
     * @return bool
     */
    public static function matches(mixed $value, ?ReflectionType $type): bool
    {
        // If no type is provided, it's valid
        if ($type === null) {
            return true;
        }

        return match (true) {
            $type instanceof ReflectionUnionType => self::matchUnionType($value, $type),
            $type instanceof ReflectionIntersectionType => self::matchIntersectionType($value, $type),
            $type instanceof ReflectionNamedType => self::matchNamedType($value, $type),
            default => false,
        };
    }

    private static function matchUnionType(mixed $value, ReflectionUnionType $type): bool
    {
        foreach ($type->getTypes() as $t) {
            // For Union: Return true on the first match
            if (self::matches($value, $t)) {
                return true;
            }
        }
        // If loop completes, no type matched
        return false;
    }

    private static function matchIntersectionType(mixed $value, ReflectionIntersectionType $type): bool
    {
        foreach ($type->getTypes() as $t) {
            // For Intersection: Return false on the first non-match
            if (!self::matches($value, $t)) {
                return false;
            }
        }
        // If loop completes, all types matched (and ReflectionIntersectionType must have types)
        return true;
    }

    private static function matchNamedType(mixed $value, ReflectionNamedType $type): bool
    {
        if ($type->allowsNull() && $value === null) {
            return true;
        }
        // If value is null but type doesn't allow null, fail early
        if ($value === null) {
            return false;
        }
        if ($type->isBuiltin()) {
            $typeName = $type->getName();
            return match ($typeName) {
                'mixed' => true,
                'iterable' => is_iterable($value),
                'callable' => is_callable($value),
                'object' => is_object($value),
                'false' => $value === false,
                'true' => $value === true,
                // PHP allows widening int to float
                'float' => is_float($value) || is_int($value),
                default => get_debug_type($value) === $typeName,
            };
        }
        // Check if value is an object before calling is_a
        if (is_object($value)) {
            // works for instances or interfaces
            return is_a($value, $type->getName());
        }

        return false;
    }
}
