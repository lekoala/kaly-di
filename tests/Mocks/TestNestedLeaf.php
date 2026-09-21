<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestNestedLeaf
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
    ) {}
}
