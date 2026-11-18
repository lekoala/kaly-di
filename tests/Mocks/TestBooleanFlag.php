<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TestBooleanFlag
{
    public function __construct(
        public bool $enabled = false,
    ) {}
}
