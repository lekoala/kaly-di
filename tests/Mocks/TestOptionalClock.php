<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use DateTimeImmutable;

class TestOptionalClock
{
    public function __construct(
        public ?DateTimeImmutable $now = null,
    ) {}
}
