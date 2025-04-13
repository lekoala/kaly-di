<?php

declare(strict_types=1);

namespace Kaly\Di;

/**
 * Reference a specific entry from the container
 * Can be used when setting parameters for a class
 */
class ServiceName
{
    public string $name;
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
