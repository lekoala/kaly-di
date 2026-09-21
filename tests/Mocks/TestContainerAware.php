<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Psr\Container\ContainerInterface;

class TestContainerAware
{
    public function __construct(
        public ContainerInterface $container,
    ) {}
}
