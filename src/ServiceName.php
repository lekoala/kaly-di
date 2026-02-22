<?php

declare(strict_types=1);

namespace Kaly\Di;

/**
 * References a container entry by name within parameter definitions
 *
 * Use Definitions::containerParameter() instead of constructing this directly.
 *
 * @internal
 */
final class ServiceName
{
    public readonly string $name;
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
