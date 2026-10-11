<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestOptionalUnbuildable
{
    public function __construct(
        public ?TestObject2 $dependency = null,
    ) {}
}
