<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestNestedRoot
{
    public function __construct(
        public TestNestedMiddle $middle,
    ) {}
}
