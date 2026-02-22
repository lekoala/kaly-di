<?php

namespace Kaly\Tests\Mocks;

class TestNullableArg
{
    public function __construct(
        public ?string $test
    ) {
    }
}
