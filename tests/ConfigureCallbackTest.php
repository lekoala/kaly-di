<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObjectSub;
use PHPUnit\Framework\TestCase;

/**
 * configure() runs callbacks on the resolved class hierarchy plus the id, and
 * must run each of them exactly once per built instance.
 */
class ConfigureCallbackTest extends TestCase
{
    /**
     * Callbacks registered on a custom id mapped to a class still run, exactly
     * once, on the produced instance. (A class id cannot be remapped to an
     * unrelated class: it must produce an instance of itself.)
     */
    public function testCallbacksOnCustomIdMappedToAClass(): void
    {
        $calls = 0;
        $definitions = Definitions::create()
            ->set('app.object', TestObject2::class)
            ->parameter(TestObject2::class, 'v', 'initial')
            ->callback('app.object', function (TestObject2 $obj) use (&$calls): void {
                $calls++;
                $obj->v .= '+cb';
            });

        $di = new Container($definitions);
        $inst = $di->get('app.object');

        $this->assertInstanceOf(TestObject2::class, $inst);
        /** @var TestObject2 $inst */
        $this->assertSame(1, $calls);
        $this->assertSame('initial+cb', $inst->v);

        // Cached: the callback does not run again
        $this->assertSame($inst, $di->get('app.object'));
        $this->assertSame(1, $calls);

        TestObject2::$counter = 0;
    }

    /**
     * When the remapped id IS part of the instance hierarchy (parent class),
     * its callbacks come from callbacksForClass() and must not run twice.
     */
    public function testCallbacksOnRemappedParentClassId(): void
    {
        $calls = 0;
        $definitions = Definitions::create()->set(
            TestObject::class,
            TestObjectSub::class,
        )->callback(TestObject::class, function (TestObject $obj) use (&$calls): void {
            $calls++;
            $obj->setVal($obj->getVal() . '+parent');
        });

        $di = new Container($definitions);
        $inst = $di->get(TestObject::class);

        $this->assertInstanceOf(TestObjectSub::class, $inst);
        $this->assertSame(1, $calls);
        $this->assertSame('+parent', $inst->getVal());
    }
}
