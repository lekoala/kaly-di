<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TestReferenceParameter
{
    public int $value;

    public function __construct(int &$value)
    {
        $this->value = $value;
    }
}
