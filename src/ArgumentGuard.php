<?php

declare(strict_types=1);

namespace Kaly\Di;

use InvalidArgumentException;

/**
 * Structural validation of provided argument lists, before any dependency is
 * resolved, plus the type guard a named variadic needs where it is consumed.
 *
 * Keeping this out of Parameters keeps that class below the complexity
 * threshold; it is an implementation detail.
 *
 * @internal
 */
final class ArgumentGuard
{
    /**
     * Split raw arguments into a positional list (reindexed in insertion
     * order) and a named map. As in a PHP call, a positional argument may not
     * follow a named one.
     *
     * @param array<mixed> $arguments
     * @return array{0: list<mixed>, 1: array<string, mixed>}
     * @throws InvalidArgumentException When a positional argument follows a named one
     */
    public static function partitionArguments(array $arguments): array
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
     * Structural checks on the provided arguments, run before any dependency
     * is resolved. Unconditional (not assert-based): they hold in production
     * too, so a bad call never reaches a factory or a container lookup.
     *
     * @param \ReflectionParameter[] $parameters
     * @param list<mixed> $positional
     * @param array<string, mixed> $named
     * @throws InvalidArgumentException
     */
    public static function validateArguments(array $parameters, array $positional, array $named): void
    {
        $count = count($parameters);
        $hasVariadic = $count > 0 && $parameters[$count - 1]->isVariadic();
        $fixedCount = $hasVariadic ? $count - 1 : $count;

        // 1. Unknown named arguments
        $available = [];
        foreach ($parameters as $parameter) {
            $available[] = $parameter->getName();
        }
        $unknown = array_values(array_diff(array_keys($named), $available));
        if ($unknown !== []) {
            sort($unknown);
            throw new InvalidArgumentException(
                'Unknown named argument(s): '
                . implode(', ', array_map(static fn(string $name): string => "`{$name}`", $unknown))
                . '. Available: '
                . implode(', ', array_map(static fn(string $name): string => "`{$name}`", $available))
                . '.',
            );
        }

        // 2. Double assignments: the same parameter passed positionally and by name
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $position = $parameter->getPosition();
            $name = $parameter->getName();
            if ($position < count($positional) && array_key_exists($name, $named)) {
                throw new InvalidArgumentException(
                    "Parameter `{$name}` is provided both positionally (index {$position}) and by name.",
                );
            }
        }
        if ($hasVariadic) {
            $variadicName = $parameters[$count - 1]->getName();
            if (count($positional) > $fixedCount && array_key_exists($variadicName, $named)) {
                throw new InvalidArgumentException(
                    "Variadic parameter `{$variadicName}` is provided both positionally and by name.",
                );
            }
            // Named variadic argument must be an array (existing contract);
            // also called from the resolution loop to narrow the value type
            if (array_key_exists($variadicName, $named)) {
                self::namedVariadicValue($variadicName, $named[$variadicName]);
            }
        }

        // 3. Surplus positional arguments with no variadic to absorb them
        if (!$hasVariadic && count($positional) > $fixedCount) {
            throw new InvalidArgumentException(
                'Too many positional arguments: expected at most '
                . $fixedCount
                . ', got '
                . count($positional)
                . ' (the callable has no variadic parameter).',
            );
        }
    }

    /**
     * A named variadic argument carries the whole list as a single array value.
     * The check runs before the resolution loop (structure) and again where the
     * value is consumed (type narrowing); both calls share one message.
     *
     * @return array<mixed>
     * @throws InvalidArgumentException When the value is not an array
     */
    public static function namedVariadicValue(string $paramName, mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Variadic argument for parameter $%s must be an array when passed by name, got %s.',
                $paramName,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
