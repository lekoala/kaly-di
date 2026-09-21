<?php

declare(strict_types=1);

namespace Kaly\Di;

use Psr\Container\ContainerInterface;
use ReflectionParameter;

/**
 * Resolves a typed dependency from the container while preserving the
 * resolution path when the nested resolution fails.
 *
 * Keeping this out of Parameters keeps that class below the complexity
 * threshold; it is an implementation detail.
 *
 * @internal
 */
final class ParameterResolution
{
    public static function fromContainer(
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
}
