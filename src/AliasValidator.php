<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;

/**
 * Alias graph invariants.
 *
 * Kept out of Definitions so that class stays within the complexity and
 * method-count thresholds; this is an implementation detail.
 *
 * @internal
 */
final class AliasValidator
{
    /**
     * Would adding alias => target close a cycle through existing aliases?
     *
     * @param array<string,string> $aliases
     */
    public static function wouldCycle(array $aliases, string $alias, string $target): bool
    {
        $seen = [];
        $current = $target;
        while (true) {
            if ($current === $alias) {
                return true;
            }
            if (!array_key_exists($current, $aliases)) {
                return false;
            }
            if (array_key_exists($current, $seen)) {
                return true;
            }
            $seen[$current] = true;
            $current = $aliases[$current];
        }
    }

    /**
     * An alias must point to a defined id, must not carry its own parameters or
     * callbacks (they would be silently ignored), and must not create a cycle.
     *
     * @param array<string,class-string|object> $values
     * @param array<string,string> $aliases
     * @param array<string,array<string,mixed>> $parameters
     * @param array<string,array<string|int,Closure>> $callbacks
     */
    public static function assertValid(array $values, array $aliases, array $parameters, array $callbacks): void
    {
        foreach ($aliases as $alias => $target) {
            // PHP turns numeric-string ids into int array keys; normalize before
            // the typed lookups below (wouldCycle() requires a string).
            $alias = (string) $alias;
            if (array_key_exists($alias, $parameters) || array_key_exists($alias, $callbacks)) {
                throw new DefinitionException("`{$alias}` is an alias and cannot have parameters or callbacks.");
            }
            if (!array_key_exists($target, $values) && !array_key_exists($target, $aliases)) {
                throw new DefinitionException("Alias `{$alias}` points to `{$target}`, which is not defined.");
            }
            if (self::wouldCycle($aliases, $alias, $target)) {
                throw new DefinitionException("Alias `{$alias}` creates an alias cycle.");
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $parameters
     * @param array<string,array<string|int,Closure>> $callbacks
     */
    public static function assertHasNoOwnConfiguration(string $alias, array $parameters, array $callbacks): void
    {
        if (array_key_exists($alias, $parameters) || array_key_exists($alias, $callbacks)) {
            throw new DefinitionException(
                "Cannot alias `{$alias}`: parameters or callbacks are configured for it. Configure the target instead.",
            );
        }
    }
}
