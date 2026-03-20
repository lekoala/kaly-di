<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionClass;
use ReflectionParameter;

/**
 * Static cache for reflection data to improve performance
 *
 * @internal
 */
final class ReflectionCache
{
    /**
     * @var array<string,array{ReflectionClass<object>, ReflectionParameter[]}>
     */
    private static array $classes = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return array{ReflectionClass<T>, ReflectionParameter[]}
     */
    public static function get(string $class): array
    {
        if (!isset(self::$classes[$class])) {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            $parameters = $constructor ? $constructor->getParameters() : [];
            self::$classes[$class] = [$reflection, $parameters];
        }

        /** @var array{ReflectionClass<T>, ReflectionParameter[]} $result */
        $result = self::$classes[$class];
        return $result;
    }
}
