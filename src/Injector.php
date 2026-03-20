<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionClass;

/**
 * Invoke callables or create fresh objects with automatic dependency resolution
 *
 * Can optionally use a PSR-11 container to resolve missing arguments.
 *
 * @link https://github.com/yiisoft/injector
 * @link https://github.com/PHP-DI/Invoker
 */
final class Injector
{
    protected readonly ?ContainerInterface $container;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Invoke any callable and resolve classes using the di container
     * You can pass named arguments or positional arguments with ...$arguments
     *
     * @param callable $callable
     * @param array<mixed> ...$arguments
     * @return mixed
     */
    public function invoke(callable $callable, ...$arguments)
    {
        $closure = Closure::fromCallable($callable);
        $reflection = new ReflectionFunction($closure);
        $parameters = $reflection->getParameters();

        $resolvedParameters = Parameters::resolveParameters($parameters, $arguments, $this->container);
        $flatArguments = Parameters::flattenArguments($parameters, $resolvedParameters);

        return $reflection->invoke(...$flatArguments);
    }

    /**
     * Build a fresh object based on its class
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<mixed> ...$arguments
     * @return T
     */
    public function make(string $class, ...$arguments)
    {
        $reflection = new ReflectionClass($class);

        // If we try to instantiate an interface, we need the container to map it to a class
        if ($reflection->isInterface()) {
            if (!$this->container) {
                throw new InvalidArgumentException("Cannot instantiate an interface without a container");
            }
            // Resolve to the concrete class via the container, then build a fresh instance
            $resolved = $this->container->get($class);
            assert(is_object($resolved));
            return $this->make($resolved::class, ...$arguments);
        }

        $constructor = $reflection->getConstructor();

        // Collect constructor's arguments. There might be no constructor
        $parameters = $constructor ? $constructor->getParameters() : [];

        $resolvedParameters = Parameters::resolveParameters($parameters, $arguments, $this->container);
        $flatArguments = Parameters::flattenArguments($parameters, $resolvedParameters);

        return $reflection->newInstanceArgs($flatArguments);
    }
}
