<?php

namespace Kaly\Tests\Mocks;

class TestVariadicArg
{
    /** @var string[] */
    public array $names;

    public function __construct(string ...$names)
    {
        $this->names = $names;
    }
}
