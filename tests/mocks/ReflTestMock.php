<?php

namespace Kaly\Tests\Mocks;

use stdClass;

class ReflTestMock
{
    public function someMethod(
        string|int $union,
        $noType,
        stdClass $class,
        ReflTestMockInterface1&ReflTestMockInterface2 $intersection,
        ?stdClass $null,
        int $builtin
    ): void {
        // empty
    }

    public function methodWithManyParams(
        string $param1,
        int $param2,
        bool $param3,
        array $param4 = [],
        int $param5 = 0,
        bool $param6 = false,
        ?string $param7 = null,
        ?ReflTestMockObject $param8 = null,
        string ...$variadic
    ): void {
        // empty
    }

    public function methodWithContainer(
        stdClass $param1,
        string $param2
    ): void {
        // empty
    }

    public function methodWithDefaultTypes(
        array $a,
        string $b,
        bool $c,
        int $d,
        float $e,
        ReflTestMock $other
    ) {
        // empty
    }
}
