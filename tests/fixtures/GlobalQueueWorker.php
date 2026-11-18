<?php

declare(strict_types=1);

// Outside the PSR-4 namespaces: a short (global) class name. PHP resolves its
// case-insensitively, but writable ids keep the spelling as written, so a
// definition may spell it differently from the declared name.

use Kaly\Tests\Mocks\TestQueueJob;

class GlobalQueueWorker implements TestQueueJob
{
    public function __construct(
        public string $queue = 'default',
    ) {}
}
