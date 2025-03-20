<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\Container;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Tests\Mocks\TestObject5;
use Kaly\Tests\Mocks\TestAltInterface;
use Kaly\Tests\Mocks\TestObject6;
use InvalidArgumentException;
use Kaly\Tests\Mocks\TestObject5Parent;
use ArgumentCountError;

class InjectorTest extends TestCase
{
    public function testInjectorCreate(): void
    {
        $injector = new Injector();
        $inst = $injector->make(TestObject5::class, v: 'test', v2: 'test');

        $this->assertInstanceOf(TestObject5::class, $inst);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('test', $inst->v2);
        $this->assertEquals([], $inst->arr); // it was provided automatically
        $this->assertEquals(null, $inst->v3); // its nullable

        // You can also use ...array if you don't like named arguments
        $inst = $injector->make(TestObject5::class, ...['v' => 'test', 'v2' => 'test']);
        $this->assertEquals($inst, $inst);

        $definitions = Definitions::create()
            ->parameter(TestObject5::class, 'v', 'from definitions')
            ->lock();

        $container = new Container($definitions);
        $injectorContainer = new Injector($container);

        // make() creates a fresh class without taking the definitions into account
        // This time, we skip 'v'
        $inst = $injector->make(TestObject5::class, v2: 'test');
        $this->assertEquals('', $inst->v);
        $this->assertEquals('test', $inst->v2);

        // but with the container, the definitions are used
        $parameters = $definitions->allParametersFor(TestObject5::class);
        $this->assertEquals('from definitions', $parameters['v']);

        $instFromContainer = $container->get(TestObject5::class);
        $this->assertNotEquals($inst, $instFromContainer);
        $this->assertEquals('from definitions', $instFromContainer->v);
        // Injector will return empty strings if nulls are not accepted
        $this->assertEquals('', $instFromContainer->v2);

        // if object has been created by container, the injector will use it
        $fn = fn(TestObject5 $a): \Kaly\Tests\Mocks\TestObject5 => $a;
        $this->assertEquals($instFromContainer, $injectorContainer->invoke($fn));

        // if we make a parent class, any unprovided parameter is provided (and cached) by the container
        $instWithoutParam = $injectorContainer->make(TestObject5Parent::class);
        $this->assertEquals($instFromContainer, $instWithoutParam->v);

        // With an injector without container, this would not work because it cannot build a TestObject5
        $this->expectException(ArgumentCountError::class);
        $this->assertEquals($instFromContainer, $injector->invoke($fn)); // too few arguments, TestObject5 is not provided
    }

    public function testInjectorTypes(): void
    {
        $injector = new Injector();
        $fn = fn(): string => 'test';
        $this->assertEquals('test', $injector->invoke($fn));
        // no value = empty string
        $fn = fn(string $a): string => $a;
        $this->assertEquals('', $injector->invoke($fn));
        // provide a value (named)
        $fn = fn(string $a, string $b): string => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, b: 'other', a: 'test'));
        // default value is preferred
        $fn = fn(string $a, string $b = 'other'): string => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, a: 'test'));
        // provide a value (positional). Null values must work
        $fn = fn(string $a, string $b): string => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, 'test', 'other'));
        $fn = fn(string $a, ?string $b, ?string $c): string => $a . $b . $c;
        $this->assertEquals('testother', $injector->invoke($fn, 'test', null, 'other'));
        // you can use invokeArray syntax (named, positional)
        $fn = fn(string $a): string => $a;
        $this->assertEquals('test', $injector->invokeArray($fn, [
            'a' => 'test'
        ]));
        $this->assertEquals('test', $injector->invokeArray($fn, [
            'test'
        ]));
        // complex types
        $fn = fn(string|bool $a): string|bool => $a;
        $this->assertEquals(true, $injector->invoke($fn, true));
        $this->assertEquals('test', $injector->invoke($fn, 'test'));
        // intersection type
        $fn = fn(TestInterface&TestAltInterface $intersection) => $intersection;
        $demo = new TestObject6('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        // union type
        $fn = fn(TestInterface|TestAltInterface $intersection) => $intersection;
        $demo = new TestObject5('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        $demo = new TestObject6('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        // provide an invalid value throws AssertionError
        $fn = fn(string $a): string => $a;
        $this->expectException(AssertionError::class);
        $this->assertEquals('test', $injector->invoke($fn, a: true));
    }

    public function testMakeInterfaceWithoutContainer(): void
    {
        $injector = new Injector();
        $this->expectException(InvalidArgumentException::class);
        $injector->make(TestInterface::class);
    }

    public function testMakeInterfaceWithContainer(): void
    {
        $definitions = Definitions::create()->set(TestInterface::class, TestObject::class);
        $container = new Container($definitions);
        $injector = new Injector($container);
        $inst = $injector->make(TestInterface::class);
        $this->assertInstanceOf(TestObject::class, $inst);

        // Injector always create fresh objects
        $inst2 = $injector->make(TestInterface::class);
        $this->assertNotSame($inst, $inst2);
    }

    /**
     * Test calling makeArray
     *
     * @return void
     */
    public function testMakeArray(): void
    {
        $injector = new Injector();
        $inst = $injector->makeArray(TestObject5::class, ['v' => 'test', 'v2' => 'test2']);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('test2', $inst->v2);

        // positional should work
        $inst2 = $injector->makeArray(TestObject5::class, ['test', 'test2']);
        $this->assertEquals('test', $inst2->v);
        $this->assertEquals('test2', $inst2->v2);
    }
}
