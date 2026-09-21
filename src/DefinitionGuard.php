<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;

/**
 * Configuration invariants and development assertions for Definitions.
 *
 * Kept out of Definitions so that class stays within the complexity and
 * method-count thresholds; this is an implementation detail.
 *
 * @internal
 */
final class DefinitionGuard
{
    public static function assertNotLocked(bool $locked): void
    {
        if ($locked) {
            throw new DefinitionException('Definitions are locked and cannot be modified.');
        }
    }

    /**
     * A given id can only be owned once, whether by a value or by an alias.
     *
     * @param array<string,class-string|object> $values
     * @param array<string,string> $aliases
     */
    public static function assertNotDefined(array $values, array $aliases, string $id): void
    {
        if (array_key_exists($id, $values) || array_key_exists($id, $aliases)) {
            throw new DefinitionException(
                "Service `{$id}` is already defined. Use rebind() if replacing it is intentional.",
            );
        }
    }

    /**
     * @param array<string,string> $aliases
     */
    public static function assertNotAlias(array $aliases, string $id): void
    {
        if (array_key_exists($id, $aliases)) {
            throw new DefinitionException("`{$id}` is an alias: configure its target instead of the alias itself.");
        }
    }

    public static function assertUsableId(string $id): void
    {
        if ($id === \stdClass::class) {
            throw new DefinitionException('Cannot set stdClass as id');
        }
    }

    /**
     * Development assertion: the value is an object or an existing class name.
     *
     * class_exists() may autoload the class, so this check must not run in
     * production for services the runtime never visits.
     *
     * @param class-string|object $value
     */
    public static function assertValidDefinition(string $id, string|object $value): void
    {
        assert(is_object($value) || class_exists($value), "Value for `{$id}` is not valid");
    }

    /**
     * Development assertion helper: is a rebind() value compatible with a typed
     * id? Closures are free (their result is only known at execution time) and
     * untyped ids accept anything. Only called from assert().
     *
     * @param class-string|object $value
     */
    public static function isCompatibleReplacement(string $id, string|object $value): bool
    {
        if ($value instanceof Closure || !self::isTypedId($id)) {
            return true;
        }
        return is_string($value) ? is_a($value, $id, true) : $value instanceof $id;
    }

    /**
     * Diagnostic for a failed isCompatibleReplacement() assertion.
     *
     * @param class-string|object $value
     */
    public static function replacementMismatch(string $id, string|object $value): string
    {
        if (is_string($value)) {
            return "Class `{$value}` does not implement `{$id}`";
        }
        if ($value instanceof Closure) {
            return "Closure does not implement `{$id}`";
        }
        $valueClass = $value::class;
        return "Object `{$valueClass}` does not implement `{$id}`";
    }

    /**
     * Human-readable description of a definition value for error messages.
     *
     * Objects and closures include their instance id so that two distinct
     * instances of the same class are not rendered identically.
     *
     * @param class-string|object $value
     */
    public static function describeValue(string|object $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof Closure) {
            return 'Closure#' . spl_object_id($value);
        }
        return $value::class . '#' . spl_object_id($value);
    }

    /**
     * Whether an id denotes an interface or an abstract class.
     *
     * May autoload, so it is only ever reached from within assert().
     */
    private static function isTypedId(string $id): bool
    {
        return interface_exists($id) || class_exists($id) && (new \ReflectionClass($id))->isAbstract();
    }
}
