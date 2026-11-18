<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TestQueueWorker implements TestQueueJob
{
    public function __construct(
        public string $queue = 'default',
    ) {}
}
