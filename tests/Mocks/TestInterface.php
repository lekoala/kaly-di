<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

interface TestInterface
{
    public function getVal(): string;

    public function setVal(string $val): void;
}
