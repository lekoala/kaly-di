<?php

namespace Kaly\Tests\Mocks\Callback;

class OrderParent implements IOrder1
{
    public array $log = [];

    public function setOrder(string $val): void
    {
        $this->log[] = $val;
    }
}
