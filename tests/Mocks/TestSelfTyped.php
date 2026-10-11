<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestSelfTyped extends TestParent
{
    public static function same(self $node): self
    {
        return $node;
    }

    public static function up(parent $node): TestParent
    {
        return $node;
    }
}
