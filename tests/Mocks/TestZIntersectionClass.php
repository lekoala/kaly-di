<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

/**
 * Test intersection, this could accept TestObject6
 */
class TestZIntersectionClass
{
    public TestInterface&TestAltInterface $v;

    public function __construct(TestInterface&TestAltInterface $v)
    {
        $this->v = $v;
    }
}
