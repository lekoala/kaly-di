<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestObjectSelf
{
    protected TestObjectSelf $obj;

    public function __construct(TestObjectSelf $obj)
    {
        $this->obj = $obj;
    }
}
