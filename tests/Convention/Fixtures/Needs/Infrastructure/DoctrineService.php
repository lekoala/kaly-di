<?php

declare(strict_types=1);

namespace Kaly\Tests\Convention\Fixtures\Needs\Infrastructure;

use Kaly\Tests\Convention\Fixtures\Needs\Domain\Service;

final class DoctrineService implements Service
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
    ) {}
}
