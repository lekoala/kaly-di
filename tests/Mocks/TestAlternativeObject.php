<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TestAlternativeObject implements TestInterface
{
    public function getVal(): string
    {
        return 'alternative';
    }

    public function setVal(string $val): void {}
}
