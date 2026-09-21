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
            if (self::matches($value, $t)) {
                return true;
            }
        }
        return false;
    }

    private static function matchIntersectionType(mixed $value, ReflectionIntersectionType $type): bool
    {
        foreach ($type->getTypes() as $t) {
            if (!self::matches($value, $t)) {
                return false;
            }
        }
        return true;
    }

    private static function matchNamedType(mixed $value, ReflectionNamedType $type): bool
    {
        if ($value === null) {
            return $type->allowsNull();
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
        if (is_object($value)) {
            // works for instances or interfaces
            return is_a($value, $type->getName());
        }

        return false;
    }
}
