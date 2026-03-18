<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;

/**
 * Manages resolver rules that map constructor arguments to specific container entries
 *
 * Resolvers define how the container selects a service when there are multiple candidates
 * for a given constructor parameter type. They support matching by parameter name,
 * by the consuming class, or via a wildcard closure.
 *
 * @internal
 */
final class ResolverRegistry
{
    /**
     * Resolve arguments based on custom conditions
     * @var array<class-string,array<string,callable|string>>
     */
    protected array $resolvers = [];

    /**
     * Define how to map constructor arguments when building objects of a given class
     *
     * @param class-string $class The class of the argument to resolve
     * @param string $key The parameter name, the class name, or * for all parameters (when using a closure)
     * @param callable|string $value fn(string $name, string $class) or a definition id
     */
    public function resolve(string $class, string $key, callable|string $value): void
    {
        $this->resolvers[$class][$key] = $value;
    }

    /**
     * Define a wildcard resolver for all parameters of a given type
     *
     * @param class-string $class The class of the argument to resolve
     * @param callable $value fn(string $name, string $class)
     */
    public function resolveAll(string $class, callable $value): void
    {
        $this->resolve($class, '*', $value);
    }

    /**
     * @param class-string $class
     * @return array<string,callable|string>
     */
    public function resolversFor(string $class): array
    {
        return $this->resolvers[$class] ?? [];
    }

    /**
     * Check if we have any custom resolver that helps us to map a specific variable for a class to a registered service
     *
     * @param string $name
     * @param class-string $typeName
     * @param string $class
     * @return ?string
     */
    public function resolveName(string $name, string $typeName, string $class): ?string
    {
        $resolvers = $this->resolversFor($typeName);
        if (empty($resolvers)) {
            return null;
        }

        // Exact parameter name match
        if (array_key_exists($name, $resolvers)) {
            $value = $resolvers[$name];
            $serviceName = $value instanceof Closure ? $value($name, $class) : $value;
            assert(is_string($serviceName));
            return $serviceName;
        }

        // Class/Interface Context match
        static $typeExistsCache = [];
        foreach ($resolvers as $key => $value) {
            if ($key === '*' || $key === $name) {
                continue;
            }

            $isType = $typeExistsCache[$key] ?? ($typeExistsCache[$key] = class_exists($key) || interface_exists($key));
            if ($isType && is_a($class, $key, true)) {
                $serviceName = $value instanceof Closure ? $value($name, $class) : $value;
                assert(is_string($serviceName));
                return $serviceName;
            }
        }

        // Wildcard fallback
        if (array_key_exists('*', $resolvers)) {
            $value = $resolvers['*'];
            if ($value instanceof Closure) {
                $serviceName = $value($name, $class);
                assert(is_string($serviceName));
                return $serviceName;
            }
        }

        return null;
    }

    /**
     * @return array<class-string,array<string,callable|string>>
     */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }

    /**
     * @param array<class-string,array<string,callable|string>> $resolvers
     */
    public function setResolvers(array $resolvers): void
    {
        $this->resolvers = $resolvers;
    }

    public function sort(): void
    {
        ksort($this->resolvers);
    }
}
