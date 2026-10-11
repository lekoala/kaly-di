<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestOptionalDeps
{
    public function __construct(
        public ?TestObject $object = null,
        public ?TestInterface $interface = null,
    ) {}
}
