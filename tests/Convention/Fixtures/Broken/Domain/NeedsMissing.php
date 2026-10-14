<?php

declare(strict_types=1);

namespace Kaly\Tests\Convention\Fixtures\Broken\Domain;

final class NeedsMissing
{
    public function __construct(
        public ?Missing $missing = null,
    ) {}
}
