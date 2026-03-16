<?php

namespace Kaly\Tests\Mocks;

class TestObject5Parent
{
    public TestObject5 $v;
    public function __construct(TestObject5 $v)
    {
        $this->v = $v;
    }
}
