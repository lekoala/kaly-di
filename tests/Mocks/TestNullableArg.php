<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestNullableArg
{
    public function __construct(
        public ?string $test,
    ) {}
}
