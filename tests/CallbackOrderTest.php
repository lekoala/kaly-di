<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\Callback\IOrder1;
use Kaly\Tests\Mocks\Callback\IOrder2;
use Kaly\Tests\Mocks\Callback\OrderChild;
use Kaly\Tests\Mocks\Callback\OrderParent;
use PHPUnit\Framework\TestCase;

class CallbackOrderTest extends TestCase
{
    public function testExecutionOrder(): void
    {
        $definitions = Definitions::create()
            ->callback(IOrder1::class, fn(IOrder1 $o) => $o->setOrder('I1'))
            ->callback(IOrder2::class, fn(IOrder2 $o) => $o->setOrder('I2'))
            ->callback(OrderParent::class, fn(OrderParent $o) => $o->setOrder('Parent'))
            ->callback(OrderChild::class, fn(OrderChild $o) => $o->setOrder('Child'))
            ->set('named', OrderChild::class)
            ->callback('named', fn(OrderChild $o) => $o->setOrder('Named'));

        $container = new Container($definitions);

        // Test requesting concrete class
        /** @var OrderChild $child */
        $child = $container->get(OrderChild::class);

        // Potential outcome of my fix (sorting interfaces alphabetically):
        // I1, I2 (alphabetical depends on names, here IOrder1, IOrder2)
        // Then Parent, then Child
        $expected = ['I1', 'I2', 'Parent', 'Child'];
        $this->assertEquals($expected, $child->log, "Order for concrete class is incorrect");

        // Test requesting named service
        /** @var OrderChild $named */
        $named = $container->get('named');
        $this->assertEquals(['I1', 'I2', 'Parent', 'Child', 'Named'], $named->log, "Order for named service is incorrect");
    }
}
