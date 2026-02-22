<?php

declare(strict_types=1);

namespace Kaly\Di;

use ReflectionParameter;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use Psr\Container\ContainerInterface;
use InvalidArgumentException;

/**
 * Helper class to deal with parameters resolution.
 */
final class Parameters
{
    /**
     * @param ReflectionParameter $param
     * @return array<ReflectionNamedType|ReflectionIntersectionType>
     */
    public static function getParameterTypes(ReflectionParameter $param): array
    {
        $reflectionType = $param->getType();

        if (!$reflectionType) {
            return [];
        }

        if ($reflectionType instanceof ReflectionUnionType) {
            return $reflectionType->getTypes();
        }

        if ($reflectionType instanceof ReflectionNamedType || $reflectionType instanceof ReflectionIntersectionType) {
            return [$reflectionType];
        }

        return [];
    }

    /**
     * Checks value compatibility against potentially complex PHP type hints
     * (nullable, union, intersection, class/interface, built-in)
     *
     * @param mixed $value
     * @param ReflectionType|null $type
     * @return bool
     */
    public static function valueMatchType(mixed $value, ?ReflectionType $type): bool
    {
        // If no type is provided, it's valid
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                // For Union: Return true on the first match
                if (self::valueMatchType($value, $t)) {
                    return true;
                }
            }
            // If loop completes, no type matched
            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $t) {
                // For Intersection: Return false on the first non-match
                if (!self::valueMatchType($value, $t)) {
                    return false;
                }
            }
            // If loop completes, all types matched (and ReflectionIntersectionType must have types)
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->allowsNull() && $value === null) {
                return true;
            }
            // If value is null but type doesn't allow null, fail early
            if ($value === null) {
                return false;
            }
            if ($type->isBuiltin()) {
                // get_debug_type() function returns the exact types that you use in scalar typing.
                return get_debug_type($value) === $type->getName();
            }
            // Check if value is an object before calling is_a
            if (is_object($value)) {
                // works for instances or interfaces
                return is_a($value, $type->getName());
            }
        }

        // If type is not built-in and value is not an object, it's a mismatch
        return false;
    }

    /**
     * Given an array of ReflectionParameters, returns resolved parameters
     * @param ReflectionParameter[] $parameters
     * @param array<mixed> $arguments
     * @param ?ContainerInterface $container If provided, 'ReflectionNamedType' will be looked for by id in the container
     * @return array<string|mixed>|array<mixed>
     */
    public static function resolveParameters(array $parameters, array $arguments, ?ContainerInterface $container = null): array
    {
        // If we have an int indexed array, arguments are positional
        // Use named keys if no arguments are provided
        $isPositional = count($arguments) === 0 ? false : array_is_list($arguments);

        $resolvedArguments = [];
        $count = -1;
        foreach ($parameters as $parameter) {
            $count++;

            $paramType = $parameter->getType();
            $paramName = $parameter->getName();

            // Last argument is variadic
            if ($parameter->isVariadic()) {
                // Handle named variadic argument (expecting an array)
                if (array_key_exists($paramName, $arguments)) {
                    $providedVariadic = $arguments[$paramName];
                    if (!is_array($providedVariadic)) {
                        throw new InvalidArgumentException(
                            sprintf(
                                "Variadic argument for parameter $%s must be an array when passed by name, got %s.",
                                $paramName,
                                get_debug_type($providedVariadic)
                            )
                        );
                    }
                    // Type check elements if variadic has a type hint (e.g., string ...$names)
                    if ($paramType instanceof ReflectionNamedType) {
                        foreach ($providedVariadic as $variadicArg) {
                            assert(
                                self::valueMatchType($variadicArg, $paramType),
                                "parameter `$paramName` doesn't support " . get_debug_type($variadicArg)
                            );
                        }
                    }
                    $resolvedArguments[$paramName] = $providedVariadic;
                } else {
                    // Merge remaining arguments
                    $resolvedArguments = array_merge($resolvedArguments, array_slice($arguments, $count));
                }

                // Variadic is always the last parameter
                break;
            }


            // Check if argument is already provided, including null values
            $argumentKey = $isPositional ? $count : $paramName;
            $isProvided = array_key_exists($argumentKey, $arguments);

            if ($isProvided) {
                $providedArgument = $arguments[$argumentKey];

                // Provided argument doesn't match type
                assert(
                    self::valueMatchType($providedArgument, $paramType),
                    "parameter `$paramName` doesn't support " . get_debug_type($providedArgument)
                );

                $resolvedArguments[$argumentKey] = $providedArgument;
            } else {
                try {
                    $resolvedArgument = self::resolveSingleParameter($parameter, $container);
                    $resolvedArguments[$argumentKey] = $resolvedArgument;
                } catch (UnresolvableParameterException $e) {
                    // Simply ignore, this will trigger an ArgumentCount error
                }
            }
        }

        return $resolvedArguments;
    }

    private static function resolveSingleParameter(ReflectionParameter $parameter, ?ContainerInterface $container): mixed
    {
        // Resolve using the container for any valid type
        $types = self::getParameterTypes($parameter);
        foreach ($types as $type) {
            if ($type instanceof ReflectionNamedType) {
                if (!$type->isBuiltin()) {
                    // The container must use the class or interface name as id
                    $name = $type->getName();
                    if ($container && $container->has($name)) {
                        return $container->get($name);
                    }
                }
            }
        }

        // Use code-provided default
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // It allows null
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new UnresolvableParameterException(
            sprintf(
                "Cannot resolve required parameter #%d ($%s) of type %s.",
                $parameter->getPosition(),
                $parameter->getName(),
                self::reflectionTypeToString($parameter->getType())
            )
        );
    }

    private static function reflectionTypeToString(?ReflectionType $type): string
    {
        // Reflection types have __toString magic method which is usually sufficient
        return $type === null ? 'mixed' : (string) $type;
    }
}
