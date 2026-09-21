<?php

declare(strict_types=1);

namespace Kaly\Di;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use WeakMap;

/**
 * Invoke callables or create fresh instances of concrete classes.
 *
 * The Injector depends only on PSR-11. It uses the container to resolve the
 * object dependencies of a callable/constructor, but never reads Kaly
 * definitions: make() instantiates a concrete class independently of how it
 * may be configured in the container.
 *
 * @link https://github.com/yiisoft/injector
 * @link https://github.com/PHP-DI/Invoker
 */
final class Injector
{
    /** @var WeakMap<Closure, array{0: ReflectionFunction, 1: array<\ReflectionParameter>}> */
    private WeakMap $callableCache;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        $this->callableCache = new WeakMap();
    }

    /**
     * Invoke any callable and resolve object dependencies using the container.
     * You can pass named arguments or positional arguments with ...$arguments
     *
     * @param callable $callable
     * @param array<mixed> ...$arguments
     * @return mixed
     */
    public function invoke(callable $callable, ...$arguments)
    {
        $closure = $callable instanceof Closure ? $callable : Closure::fromCallable($callable);
        [$reflection, $parameters] = $this->reflect($closure);
        $flatArguments = Parameters::resolveParameters($parameters, $arguments, $this->container);
        return $reflection->invoke(...$flatArguments);
    }

    /**
     * @return array{0: ReflectionFunction, 1: array<\ReflectionParameter>}
     */
    private function reflect(Closure $closure): array
    {
        if (!$this->callableCache->offsetExists($closure)) {
            $reflection = new ReflectionFunction($closure);
            $this->callableCache[$closure] = [$reflection, $reflection->getParameters()];
        }
        return $this->callableCache[$closure];
    }

    /**
     * Build a fresh object from a concrete class.
     *
     * Object dependencies are resolved through the PSR-11 container. Every
     * other argument must be provided explicitly, have a default value or be
     * nullable. An interface or abstract class cannot be built.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<mixed> ...$arguments
     * @return T
     */
    public function make(string $class, ...$arguments)
    {
        try {
            [$reflection, $parameters] = ReflectionCache::reflection($class);
        } catch (\ReflectionException) {
            throw new InvalidArgumentException("Class `{$class}` does not exist");
        }

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException("`{$class}` is not instantiable");
        }

        $flatArguments = Parameters::resolveParameters($parameters, $arguments, $this->container);

        /** @var T */
        return $reflection->newInstanceArgs($flatArguments);
    }
}
