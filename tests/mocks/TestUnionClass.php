<?php

namespace Kaly\Tests\Mocks;

class TestUnionClass
{
    public string|TestObject $v;

    public function __construct(string|TestObject $v)
    {
        $this->v = $v;
    }
}
