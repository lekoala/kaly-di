<?php

namespace Kaly\Tests\Mocks;

/**
 * This object implements two interfaces and can be consumed by TestZIntersectionClass
 */
class TestObject6 implements TestAltInterface, TestInterface
{
    public string $v;
    public string $v2;
    public ?string $v3;
    public array $arr;
    public function __construct(string $v, string $v2, array $arr, ?string $v3 = null)
    {
        $this->v = $v;
        $this->v2 = $v2;
        $this->v3 = $v3;
        $this->arr = $arr;
    }

    public function getVal(): string
    {
        return $this->v;
    }

    public function setVal(string $val): void
    {
        $this->v = $val;
    }
}
