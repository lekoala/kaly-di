<?php

declare(strict_types=1);

namespace Kaly\Di;

use InvalidArgumentException;
use ReflectionParameter;

/**
 * Argument-side guards and the final shape of a resolved argument list.
 *
 * Kept out of Parameters so that class stays within complexity thresholds.
 * This is an implementation detail.
 *
 * @internal
 */
final class ArgumentGuard
{
    /**
     * Sentinel for an optional parameter that PHP declares without an exposed
     * default value (many internal functions). Such an argument must be
     * omitted rather than filled with null.
     */
    private static ?\stdClass $omittedMarker = null;

    /**
     * Reflection invocation cannot preserve the caller's references: an array
     * of arguments is unpacked by value, so a by-reference parameter would
     * emit a warning and leave the caller's variable untouched. Reject those
     * signatures up front instead of returning a misleading result.
     *
     * @param \ReflectionParameter[] $parameters
     * @throws InvalidArgumentException
     */
    public static function assertNoReferenceParameters(array $parameters): void
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

    public static function omit(): \stdClass
    {
        return self::$omittedMarker ??= new \stdClass();
    }

    public static function isOmitted(mixed $value): bool
    {
        return self::$omittedMarker !== null && $value === self::$omittedMarker;
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
     * @throws UnresolvableParameterException
     */
    public static function finalize(array $resolved, array $solid, array $omitted): array
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

    public static function unresolvable(ReflectionParameter $parameter): UnresolvableParameterException
    {
        $type = $parameter->getType();

        return new UnresolvableParameterException(
            sprintf(
                'Cannot resolve required parameter #%d ($%s) of type %s.',
                $parameter->getPosition(),
                $parameter->getName(),
                $type === null ? 'mixed' : (string) $type,
            ),
            0,
            null,
            $parameter->getName(),
        );
    }
}
