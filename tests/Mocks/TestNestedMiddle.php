<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestNestedMiddle
{
    public function __construct(
        public TestNestedLeaf $leaf,
    ) {}
}
