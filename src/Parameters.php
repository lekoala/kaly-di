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
 * See resolveParameters() for the resolution contract.
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
     * Resolve constructor/callable arguments into a final positional list,
     * ready to be unpacked for a Reflection call (variadic spread included).
     *
     * Resolution is always strict: a required parameter that cannot be
     * satisfied (by an explicit argument, the container, a default value or
     * null) throws an UnresolvableParameterException.
     *
     * Provided arguments are normalized and validated before any dependency is
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
     * and must come first; named arguments fill parameters by name. A named
     * variadic argument carries the whole list as a single array; its keys are
     * discarded (`array_values`) so the result stays a pure list.
     *
     * @param \ReflectionParameter[] $parameters
     * @param array<mixed> $arguments
     * @param ContainerInterface|null $container
     * @return list<mixed>
     * @throws UnresolvableParameterException
     * @throws InvalidArgumentException
     * @throws CircularReferenceException When the container resolves a parameter that has circular dependencies
     */
    public static function resolveParameters(
        array $parameters,
        array $arguments,
        ?ContainerInterface $container = null,
    ): array {
        [$provided, $variadicValues] = self::normalizeArguments($parameters, $arguments);

        $resolved = [];
        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();

            if ($parameter->isVariadic()) {
                foreach ($variadicValues as $variadicValue) {
                    $resolved[] = $variadicValue;
                }
                break;
            }

            if (array_key_exists($paramName, $provided)) {
                $resolved[] = $provided[$paramName];
                continue;
            }

            $resolved[] = self::resolveSingleParameter($parameter, $container);
        }

        return $resolved;
    }

    /**
     * Split, validate and normalize the provided arguments into provided
     * values keyed by parameter name, plus the variadic values as a pure
     * list. Runs entirely before any container access.
     *
     * @param \ReflectionParameter[] $parameters
     * @param array<mixed> $arguments
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     * @throws InvalidArgumentException
     */
    private static function normalizeArguments(array $parameters, array $arguments): array
    {
        [$positional, $named] = self::partitionArguments($arguments);

        $count = count($parameters);
        $hasVariadic = $count > 0 && $parameters[$count - 1]->isVariadic();
        $fixedCount = $hasVariadic ? $count - 1 : $count;
        $positionalCount = count($positional);

        // Preliminary checks: unknown named arguments, then surplus positionals
        self::assertNoUnknownNamedArguments($parameters, $named);
        if (!$hasVariadic && $positionalCount > $fixedCount) {
            throw new InvalidArgumentException(
                'Too many positional arguments: expected at most '
                . $fixedCount
                . ', got '
                . $positionalCount
                . ' (the callable has no variadic parameter).',
            );
        }

        $provided = [];
        $variadicValues = [];
        foreach ($parameters as $parameter) {
            $position = $parameter->getPosition();
            $paramType = $parameter->getType();
            $paramName = $parameter->getName();

            if ($parameter->isVariadic()) {
                if ($positionalCount > $fixedCount && array_key_exists($paramName, $named)) {
                    throw new InvalidArgumentException(
                        "Variadic parameter `{$paramName}` is provided both positionally and by name.",
                    );
                }
                $variadicValues = array_key_exists($paramName, $named)
                    ? self::namedVariadicValue($paramName, $named[$paramName])
                    : array_slice($positional, $position);
                if ($paramType instanceof ReflectionNamedType) {
                    foreach ($variadicValues as $value) {
                        assert(
                            TypeMatcher::matches($value, $paramType),
                            "parameter `{$paramName}` doesn't support " . get_debug_type($value),
                        );
                    }
                }
                continue;
            }

            // Reject a double assignment, then normalize the value
            if ($position < $positionalCount && array_key_exists($paramName, $named)) {
                throw new InvalidArgumentException(
                    "Parameter `{$paramName}` is provided both positionally (index {$position}) and by name.",
                );
            }

            if (array_key_exists($position, $positional)) {
                $value = $positional[$position];
            } elseif (array_key_exists($paramName, $named)) {
                $value = $named[$paramName];
            } else {
                continue;
            }

            assert(
                TypeMatcher::matches($value, $paramType),
                "parameter `{$paramName}` doesn't support " . get_debug_type($value),
            );
            $provided[$paramName] = $value;
        }

        return [$provided, $variadicValues];
    }

    /**
     * Reject named arguments that match no parameter.
     *
     * @param \ReflectionParameter[] $parameters
     * @param array<string, mixed> $named
     * @throws InvalidArgumentException
     */
    private static function assertNoUnknownNamedArguments(array $parameters, array $named): void
    {
        $available = [];
        foreach ($parameters as $parameter) {
            $available[] = $parameter->getName();
        }
        $unknown = array_values(array_diff(array_keys($named), $available));
        if ($unknown === []) {
            return;
        }

        sort($unknown);
        throw new InvalidArgumentException(
            'Unknown named argument(s): '
            . implode(', ', array_map(static fn(string $name): string => "`{$name}`", $unknown))
            . '. Available: '
            . implode(', ', array_map(static fn(string $name): string => "`{$name}`", $available))
            . '.',
        );
    }

    /**
     * Split raw arguments into a positional list (reindexed in insertion
     * order) and a named map. As in a PHP call, a positional argument may not
     * follow a named one.
     *
     * @param array<mixed> $arguments
     * @return array{0: list<mixed>, 1: array<string, mixed>}
     * @throws InvalidArgumentException When a positional argument follows a named one
     */
    private static function partitionArguments(array $arguments): array
    {
        $positional = [];
        $named = [];
        $sawNamed = false;
        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $sawNamed = true;
                $named[$key] = $value;
                continue;
            }
            if ($sawNamed) {
                throw new InvalidArgumentException('Positional arguments must be provided before named arguments.');
            }
            $positional[] = $value;
        }

        return [$positional, $named];
    }

    /**
     * A named variadic argument carries the whole list as a single array value.
     * Keys are discarded so the result is a pure list (PHP would otherwise see
     * named arguments when unpacking into array_push/reflection calls).
     *
     * @return list<mixed>
     * @throws InvalidArgumentException When the value is not an array
     */
    private static function namedVariadicValue(string $paramName, mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Variadic argument for parameter $%s must be an array when passed by name, got %s.',
                $paramName,
                get_debug_type($value),
            ));
        }

        return array_values($value);
    }

    private static function resolveSingleParameter(
        ReflectionParameter $parameter,
        ?ContainerInterface $container,
    ): mixed {
        $types = self::getParameterTypes($parameter);
        foreach ($types as $type) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            if ($container) {
                if ($container->has($name)) {
                    return self::fromContainer($parameter, $name, $container);
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

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

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

    /**
     * Resolve a typed dependency from the container while preserving the
     * resolution path when the nested resolution fails.
     */
    private static function fromContainer(
        ReflectionParameter $parameter,
        string $name,
        ContainerInterface $container,
    ): mixed {
        try {
            return $container->get($name);
        } catch (UnresolvableParameterException $e) {
            // Preserve the immediate parameter name and the nested path, so the
            // outer message stays faithful to the resolution chain.
            throw new UnresolvableParameterException(
                sprintf(
                    'Cannot resolve parameter #%d ($%s) of type %s.',
                    $parameter->getPosition(),
                    $parameter->getName(),
                    (string) $parameter->getType(),
                ),
                0,
                $e,
                $parameter->getName(),
                null,
                $e->getResolutionPath(),
            );
        }
    }

    private static function reflectionTypeToString(?ReflectionType $type): string
    {
        return $type === null ? 'mixed' : (string) $type;
    }
}
