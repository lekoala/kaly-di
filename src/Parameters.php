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
     * Sentinel returned for an optional parameter that PHP declares without an
     * exposed default value (many internal functions). Such an argument must
     * be omitted rather than filled with null.
     */
    private static ?\stdClass $omittedMarker = null;

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
     * assignments (the same parameter passed positionally and by name), the
     * shape of a named variadic and a value that does not match the declared
     * parameter type all throw an InvalidArgumentException up front.
     * A typo therefore cannot surface as a misleading
     * UnresolvableParameterException or trigger a factory unnecessarily. These
     * checks are unconditional: they also hold with zend.assertions=-1.
     *
     * Explicit arguments always win. Otherwise every non-builtin type of the
     * parameter is offered to the container (`has()`): no candidate falls
     * through to the default value, then null, then an error; a single
     * candidate is resolved with `get()` and its failure propagates, without
     * trying the default, null or another candidate; several candidates raise
     * an ambiguity error and an explicit argument is required. Candidates are
     * never built to disambiguate, and two ids stay two candidates even if they
     * could resolve to the same object. The current container itself is the
     * candidate for a parameter typed exactly `ContainerInterface`.
     *
     * `has()` means "candidate available", not "construction guaranteed": a
     * unique candidate can still fail in `get()`.
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
        self::assertNoReferenceParameters($parameters);
        [$provided, $variadicValues] = self::normalizeArguments($parameters, $arguments);

        $resolved = [];
        $solid = [];
        $omitted = [];
        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();

            if ($parameter->isVariadic()) {
                foreach ($variadicValues as $variadicValue) {
                    $resolved[] = $variadicValue;
                    $solid[] = true;
                }
                break;
            }

            if (array_key_exists($paramName, $provided)) {
                $resolved[] = $provided[$paramName];
                $solid[] = true;
                continue;
            }

            [$value, $isSolid] = self::resolveSingleParameter($parameter, $container);
            if ($value === self::omittedMarker()) {
                $omitted[count($resolved)] = $parameter;
            }
            $resolved[] = $value;
            $solid[] = $isSolid;
        }

        return self::finalizeArguments($resolved, $solid, $omitted);
    }

    /**
     * Preserve the omission of trailing optional arguments.
     *
     * PHP exposes some optional parameters without a default value (notably in
     * internal functions: `array_keys($array, $filter_value = ..., $strict =
     * false)`). Passing null there changes the call. A trailing run of such
     * arguments, including the optional arguments that follow them, is
     * therefore dropped. An omitted argument that is followed by a provided or
     * container-resolved value is materialized as null when the signature
     * allows it.
     *
     * @param list<mixed> $resolved
     * @param list<bool> $solid Whether each value is provided or resolved (not a reflection fallback)
     * @param array<int,\ReflectionParameter> $omitted
     * @return list<mixed>
     */
    private static function finalizeArguments(array $resolved, array $solid, array $omitted): array
    {
        if ($omitted === []) {
            return $resolved;
        }

        // An explicitly provided or container-resolved value cannot be omitted.
        $lastSolid = -1;
        foreach ($solid as $index => $isSolid) {
            if ($isSolid) {
                $lastSolid = $index;
            }
        }

        // Drop the optional tail that follows the last resolved value.
        $tailStart = null;
        foreach (array_keys($omitted) as $index) {
            if ($index > $lastSolid) {
                $tailStart = $index;
                break;
            }
        }
        if ($tailStart !== null) {
            $resolved = array_slice($resolved, 0, $tailStart);
            $omitted = array_filter($omitted, static fn(int $index): bool => $index < $tailStart, ARRAY_FILTER_USE_KEY);
        }

        foreach ($omitted as $index => $parameter) {
            if ($parameter->allowsNull()) {
                $resolved[$index] = null;
                continue;
            }
            throw self::unresolvable($parameter);
        }

        return array_values($resolved);
    }

    private static function omittedMarker(): \stdClass
    {
        return self::$omittedMarker ??= new \stdClass();
    }

    /**
     * Reflection invocation cannot preserve the caller's references: an array
     * of arguments is unpacked by value, so a by-reference parameter would
     * emit a warning and leave the caller's variable untouched. Reject those
     * signatures up front instead of returning a misleading result.
     *
     * @param \ReflectionParameter[] $parameters
     * @throws InvalidArgumentException
     */
    private static function assertNoReferenceParameters(array $parameters): void
    {
        $references = [];
        foreach ($parameters as $parameter) {
            if ($parameter->isPassedByReference()) {
                $references[] = '$' . $parameter->getName();
            }
        }
        if ($references === []) {
            return;
        }

        sort($references);
        throw new InvalidArgumentException(
            'Parameters passed by reference are not supported: '
            . implode(', ', $references)
            . '. The injector cannot preserve the caller\'s references.',
        );
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
                foreach ($variadicValues as $value) {
                    self::assertValueMatchesParameter($parameter, $value);
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

            self::assertValueMatchesParameter($parameter, $value);
            $provided[$paramName] = $value;
        }

        return [$provided, $variadicValues];
    }

    /**
     * Explicit arguments must match the declared parameter type, whatever
     * `zend.assertions` says. Reflection would otherwise coerce scalars in
     * production (`'false'` becoming `true` for a `bool` parameter), making
     * the value depend on the assertion mode.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValueMatchesParameter(ReflectionParameter $parameter, mixed $value): void
    {
        if (TypeMatcher::matches($value, $parameter->getType(), $parameter->getDeclaringClass())) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "parameter `%s` doesn't support %s (declared type: %s).",
            $parameter->getName(),
            get_debug_type($value),
            self::reflectionTypeToString($parameter->getType()),
        ));
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

    /**
     * @return array{0: mixed, 1: bool} The value, and whether it is a real
     * value rather than a reflection fallback (default or null).
     */
    private static function resolveSingleParameter(
        ReflectionParameter $parameter,
        ?ContainerInterface $container,
    ): array {
        if ($container !== null) {
            $candidates = self::candidates($parameter, $container);

            if (count($candidates) > 1) {
                throw new UnresolvableParameterException(
                    sprintf(
                        'Cannot resolve required parameter #%d ($%s) of type %s: ambiguous, multiple candidates available (%s). Provide an explicit argument.',
                        $parameter->getPosition(),
                        $parameter->getName(),
                        self::reflectionTypeToString($parameter->getType()),
                        implode(', ', array_map(static fn(string $id): string => "`{$id}`", array_keys($candidates))),
                    ),
                    0,
                    null,
                    $parameter->getName(),
                );
            }

            if ($candidates !== []) {
                $name = (string) array_key_first($candidates);
                // The resolver provides its own container for an exact
                // `ContainerInterface` type: this is a resolver capability, not
                // a container entry. An explicit definition was treated as a
                // regular candidate above and wins naturally.
                if ($candidates[$name]) {
                    return [$container, true];
                }
                return [self::fromContainer($parameter, $name, $container), true];
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return [$parameter->getDefaultValue(), false];
        }

        // PHP exposes some optional parameters without a default value:
        // omitting them (when possible) is the only faithful call.
        if ($parameter->isOptional()) {
            return [self::omittedMarker(), false];
        }

        if ($parameter->allowsNull()) {
            return [null, false];
        }

        throw self::unresolvable($parameter);
    }

    private static function unresolvable(ReflectionParameter $parameter): UnresolvableParameterException
    {
        return new UnresolvableParameterException(
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
     * Every object type of the parameter that the container reports as
     * available, in reflection order.
     *
     * The value is true when the resolver itself provides the value (the
     * current container for a parameter typed exactly `ContainerInterface`)
     * instead of resolving it through `get()`.
     *
     * @return array<string, bool>
     */
    private static function candidates(ReflectionParameter $parameter, ContainerInterface $container): array
    {
        $candidates = [];
        foreach (self::getParameterTypes($parameter) as $type) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = TypeMatcher::className($type, $parameter->getDeclaringClass());
            if ($container->has($name)) {
                $candidates[$name] = false;
            } elseif (strcasecmp($name, ContainerInterface::class) === 0) {
                // The resolver can always provide itself to a parameter typed
                // `ContainerInterface`, whatever the case it was written in
                // (PHP class names are case-insensitive). This is a resolver
                // capability, not a container entry: has(ContainerInterface::class)
                // stays false. An explicit definition, checked above, always wins.
                $candidates[$name] = true;
            }
        }

        return $candidates;
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
