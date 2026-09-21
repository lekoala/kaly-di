<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestAmbiguousArg
{
    public function __construct(
        public TestObject|TestObjectB $v,
    ) {}
}
