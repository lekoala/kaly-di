<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
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
     * @param ReflectionClass<object>|null $scope Declaring class, used to resolve `self` and `parent`
     * @return bool
     */
    public static function matches(mixed $value, ?ReflectionType $type, ?ReflectionClass $scope = null): bool
    {
        if ($type === null) {
            return true;
        }

        return match (true) {
            $type instanceof ReflectionUnionType => self::matchUnionType($value, $type, $scope),
            $type instanceof ReflectionIntersectionType => self::matchIntersectionType($value, $type, $scope),
            $type instanceof ReflectionNamedType => self::matchNamedType($value, $type, $scope),
            default => false,
        };
    }

    /**
     * The class named by a non-builtin type, with `self` and `parent`
     * resolved against the declaring class.
     *
     * @param ReflectionClass<object>|null $scope
     * @return class-string
     */
    public static function className(ReflectionNamedType $type, ?ReflectionClass $scope): string
    {
        /** @var class-string $name a non-builtin type names a class */
        $name = $type->getName();
        if ($scope !== null) {
            $parent = $scope->getParentClass();
            /** @var class-string $name */
            $name = match (strtolower($name)) {
                'self' => $scope->getName(),
                'parent' => $parent !== false ? $parent->getName() : $name,
                default => $name,
            };
        }
        return $name;
    }

    /**
     * @param ReflectionClass<object>|null $scope
     */
    private static function matchUnionType(mixed $value, ReflectionUnionType $type, ?ReflectionClass $scope): bool
    {
        foreach ($type->getTypes() as $t) {
            if (self::matches($value, $t, $scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param ReflectionClass<object>|null $scope
     */
    private static function matchIntersectionType(
        mixed $value,
        ReflectionIntersectionType $type,
        ?ReflectionClass $scope,
    ): bool {
        foreach ($type->getTypes() as $t) {
            if (!self::matches($value, $t, $scope)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param ReflectionClass<object>|null $scope
     */
    private static function matchNamedType(mixed $value, ReflectionNamedType $type, ?ReflectionClass $scope): bool
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
            return is_a($value, self::className($type, $scope));
        }

        return false;
    }
}
