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
    /**
     * A given id can only be owned once, whether by a value or by an alias.
     *
     * @param array<string,class-string|object> $values
     * @param array<string,string> $aliases
     * @param array<string,array{initial:?string,last:?string}> $sources
     */
    public static function assertNotDefined(array $values, array $aliases, array $sources, string $id): void
    {
        if (array_key_exists($id, $values) || array_key_exists($id, $aliases)) {
            $suffix = self::describeSources($sources[$id] ?? []);
            throw new DefinitionException(
                "Service `{$id}` is already defined{$suffix}. Use rebind() if replacing it is intentional.",
            );
        }
    }

    /**
     * Human-readable provenance for error messages, e.g.
     * ` (declared by `billing`; replaced by `demo`; incoming from `catalog`)`.
     *
     * @param array{initial?:?string,last?:?string} $source
     */
    public static function describeSources(array $source, ?string $incoming = null): string
    {
        $parts = [];
        $initial = $source['initial'] ?? null;
        $last = $source['last'] ?? null;
        if ($initial !== null) {
            $parts[] = "declared by `{$initial}`";
        }
        if ($last !== null && $last !== $initial) {
            $parts[] = "replaced by `{$last}`";
        }
        if ($incoming !== null) {
            $parts[] = "incoming from `{$incoming}`";
        }
        return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
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
        if (strcasecmp(self::normalizeId($id), \stdClass::class) === 0) {
            throw new DefinitionException('Cannot set stdClass as id');
        }
    }

    /**
     * Drop a leading backslash, as PHP does for a class name reference. Every
     * id is stored and looked up in this form. Never autoloads.
     */
    public static function normalizeId(string $id): string
    {
        return ltrim($id, '\\');
    }

    /**
     * Development assertion helper: a namespaced id naming a class or an
     * interface must be written as declared (use `Foo::class`), otherwise it
     * would not match the class hierarchy at resolution. A short id such as
     * `closure` stays a free custom id. Only called from assert(): it may
     * autoload.
     */
    public static function isCanonicalId(string $id): bool
    {
        if (!str_contains($id, '\\')) {
            return true;
        }
        $class = ReflectionCache::canonicalName($id);
        return $class === null || $class === $id;
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
     * Development assertion helper: is a value compatible with a class id (a
     * class or an interface)? Closures are free (their result is only known at
     * execution time) and custom ids accept anything. Only called from assert().
     *
     * @param class-string|object $value
     */
    public static function isCompatibleReplacement(string $id, string|object $value): bool
    {
        if ($value instanceof Closure || !self::isClassId($id)) {
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
     * Whether an id is the declared name of a class or an interface. A short
     * custom id such as `closure` is not the `Closure` class id.
     *
     * May autoload, so it is only ever reached from within assert().
     */
    private static function isClassId(string $id): bool
    {
        return ReflectionCache::canonicalName($id) === $id;
    }
}
