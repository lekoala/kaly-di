<?php

namespace Kaly\Tests\Mocks;

/**
 * A mock object that implements two interfaces to test for intersection types
 */
class ReflTestMockObject implements ReflTestMockInterface1, ReflTestMockInterface2
{
    public function test()
    {
        // empty
    }
}
