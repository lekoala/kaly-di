<?php

declare(strict_types=1);

namespace Kaly\Di;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Helper class to deal with parameters resolution.
 *
 * Resolution is always strict: a required parameter that cannot be satisfied
 * (by an explicit argument, the container, a default value or null) throws an
 * UnresolvableParameterException.
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
        return TypeMatcher::matches($value, $type);
    }

    /**
     * Resolve constructor/callable arguments.
     *
     * Provided arguments are validated structurally before any dependency is
     * resolved: unknown named arguments, surplus positional arguments, double
     * assignments (the same parameter passed positionally and by name) and the
     * shape of a named variadic all throw an InvalidArgumentException up front.
     * A typo therefore cannot surface as a misleading
     * UnresolvableParameterException or trigger a factory unnecessarily. These
     * checks are unconditional: they also hold with zend.assertions=-1.
     *
     * Explicit arguments always win, then a container entry, then the current
     * container for a parameter typed exactly `ContainerInterface`, then
     * defaults/null.
     *
     * Positional and named arguments can be mixed, as in a PHP call:
     * positionals fill parameters by position (reindexed in insertion order)
     * and must come first; named arguments fill parameters by name.
     *
     * @param \ReflectionParameter[] $parameters
     * @param array<mixed> $arguments
     * @param ContainerInterface|null $container
     * @return array<mixed>
     * @throws UnresolvableParameterException
     * @throws InvalidArgumentException
     * @throws CircularReferenceException When the container resolves a parameter that has circular dependencies
     */
    public static function resolveParameters(
        array $parameters,
        array $arguments,
        ?ContainerInterface $container = null,
    ): array {
        [$positional, $named] = ArgumentGuard::partitionArguments($arguments);
        ArgumentGuard::validateArguments($parameters, $positional, $named);

        // Auto-resolved values are stored under their position when only
        // positionals were passed (list output), under their name otherwise.
        $storeAutoAtPosition = $positional !== [] && $named === [];

        $resolvedArguments = [];
        foreach ($parameters as $parameter) {
            $position = $parameter->getPosition();
            $paramType = $parameter->getType();
            $paramName = $parameter->getName();

            // Last argument is variadic
            if ($parameter->isVariadic()) {
                if (array_key_exists($paramName, $named)) {
                    // Named variadic argument, validated to be an array
                    $providedVariadic = ArgumentGuard::namedVariadicValue($paramName, $named[$paramName]);
                    // Type check elements if variadic has a type hint (e.g., string ...$names)
                    if ($paramType instanceof ReflectionNamedType) {
                        foreach ($providedVariadic as $variadicArg) {
                            assert(
                                self::valueMatchType($variadicArg, $paramType),
                                "parameter `{$paramName}` doesn't support " . get_debug_type($variadicArg),
                            );
                        }
                    }
                    $resolvedArguments[$paramName] = $providedVariadic;
                } else {
                    // Surplus positional arguments feed the variadic; validation
                    // guarantees they start exactly at the variadic position and
                    // are contiguous, so flattenArguments() never hits a hole.
                    foreach (array_slice($positional, $position) as $offset => $value) {
                        $resolvedArguments[$position + $offset] = $value;
                    }
                }

                // Variadic is always the last parameter
                break;
            }

            // Provided positionally: stored under its (reindexed) position
            if (array_key_exists($position, $positional)) {
                $providedArgument = $positional[$position];
                assert(
                    self::valueMatchType($providedArgument, $paramType),
                    "parameter `{$paramName}` doesn't support " . get_debug_type($providedArgument),
                );
                $resolvedArguments[$position] = $providedArgument;
                continue;
            }

            // Provided by name: stored under the parameter name
            if (array_key_exists($paramName, $named)) {
                $providedArgument = $named[$paramName];
                assert(
                    self::valueMatchType($providedArgument, $paramType),
                    "parameter `{$paramName}` doesn't support " . get_debug_type($providedArgument),
                );
                $resolvedArguments[$paramName] = $providedArgument;
                continue;
            }

            $resolvedArguments[$storeAutoAtPosition ? $position : $paramName] = self::resolveSingleParameter(
                $parameter,
                $container,
            );
        }

        return $resolvedArguments;
    }

    private static function resolveSingleParameter(
        ReflectionParameter $parameter,
        ?ContainerInterface $container,
    ): mixed {
        // Resolve using the container for any valid type
        $types = self::getParameterTypes($parameter);
        foreach ($types as $type) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            // The container must use the class or interface name as id
            $name = $type->getName();
            if ($container) {
                if ($container->has($name)) {
                    return ParameterResolution::fromContainer($parameter, $name, $container);
                }
                // The resolver can always provide itself to a parameter typed
                // exactly `ContainerInterface`. This is a resolver capability,
                // not a container entry: has(ContainerInterface::class) stays
                // false. An explicit definition, checked above, always wins.
                if ($name === ContainerInterface::class) {
                    return $container;
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
                'Cannot resolve required parameter #%d ($%s) of type %s.',
                $parameter->getPosition(),
                $parameter->getName(),
                self::reflectionTypeToString($parameter->getType()),
            ),
            0,
            null,
            $parameter->getName(),
        );
    }

    private static function reflectionTypeToString(?ReflectionType $type): string
    {
        // Reflection types have __toString magic method which is usually sufficient
        return $type === null ? 'mixed' : (string) $type;
    }

    /**
     * Flattens resolved associative arguments into a purely positional array,
     * natively unpacking variadic arrays for PHP 8+ Reflection calls.
     *
     * @param \ReflectionParameter[] $parameters
     * @param array<mixed> $resolvedArguments
     * @return array<mixed>
     */
    public static function flattenArguments(array $parameters, array $resolvedArguments): array
    {
        $flat = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $pos = $parameter->getPosition();

            if (array_key_exists($name, $resolvedArguments)) {
                if (!$parameter->isVariadic() || !is_array($resolvedArguments[$name])) {
                    $flat[] = $resolvedArguments[$name];
                    continue;
                }
                array_push($flat, ...$resolvedArguments[$name]);
                continue;
            }

            if (!array_key_exists($pos, $resolvedArguments)) {
                continue;
            }

            if ($parameter->isVariadic()) {
                // Positional variadic arguments are merged into $resolvedArguments
                for ($i = $pos; array_key_exists($i, $resolvedArguments); $i++) {
                    $flat[] = $resolvedArguments[$i];
                }
                continue;
            }

            $flat[] = $resolvedArguments[$pos];
        }
        return $flat;
    }
}
