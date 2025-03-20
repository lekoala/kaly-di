<?php

namespace Kaly\Tests\Mocks;

use Exception;

class TestExceptionConstructorObject
{
    public function __construct()
    {
        throw new Exception("I don't like to be built");
    }
}
