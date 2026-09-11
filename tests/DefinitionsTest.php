<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestAltInterface;
use Kaly\Tests\Mocks\TestApp;
use Kaly\Tests\Mocks\TestExtendedApp;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject5;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionType;

abstract class DefinitionsTestAbstract {}

final class DefinitionsTestConcrete extends DefinitionsTestAbstract {}

class DefinitionsTest extends TestCase
{
    public function testCreateFromArray(): void
    {
        $arr = [
            TestInterface::class => TestObject::class,
            'app' => TestObject::class,
        ];

        $def = new Definitions($arr);
        $def2 = Definitions::create($arr);

        $this->assertEquals($def2, $def);
        $this->assertTrue($def2 !== $def);

        $this->assertTrue($def->has(TestInterface::class));
        $this->assertFalse(!$def->has(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get('app'));
        $this->assertNull($def->get('nonexistent'));
        $this->assertFalse($def->has('nonexistent'));
    }

    public function testMergeDefinitions(): void
    {
        $def1 = Definitions::create()->set('obj', TestObject::class);
        $def2 = Definitions::create()->set('obj2', TestObject2::class);

        $def1->parameter(TestObject5::class, 'v', 'provided_value');
        $def1->callback(TestObject::class, fn($obj) => $obj);

        // parameters and callbacks can come from multiple sources, latest merged wins per key
        $def2->parameter(TestObject5::class, 'v2', 'provided value');
        $def2->parameter(TestObject5::class, 'v', 'overwritten');

        $final = new Definitions();
        $final->merge($def1);
        $final->merge($def2);

        $this->assertTrue($final->has('obj'));
        $this->assertTrue($final->has('obj2'));
        $this->assertArrayHasKey(TestObject5::class, $final->getParameters());
        $this->assertSame('overwritten', $final->parametersFor(TestObject5::class)['v']);
        $this->assertSame('provided value', $final->parametersFor(TestObject5::class)['v2']);
        $this->assertArrayHasKey(TestObject::class, $final->getCallbacks());
        $this->assertCount(1, $final->callbacksFor(TestObject::class));
    }

    public function testMergeKeepsUnnamedCallbacksDistinct(): void
    {
        $callbackA = fn($obj) => $obj;
        $callbackB = fn($obj) => $obj;

        $def1 = Definitions::create()->callback(TestObject::class, $callbackA);
        $def2 = Definitions::create()->callback(TestObject::class, $callbackB);

        $def1->merge($def2);

        $callbacks = array_values($def1->callbacksFor(TestObject::class));
        $this->assertCount(2, $callbacks);
        $this->assertSame($callbackA, $callbacks[0]);
        $this->assertSame($callbackB, $callbacks[1]);
    }

    public function testNullIsNotAValidDefinition(): void
    {
        $method = new ReflectionMethod(Definitions::class, 'set');
        $type = $method->getParameters()[1]->getType();

        $this->assertInstanceOf(ReflectionType::class, $type);
        $this->assertFalse($type->allowsNull());
    }

    public function testExpand(): void
    {
        $def = Definitions::create();
        $def->set('closure', fn(ContainerInterface $c): string => 'result');
        $def->set('value', TestObject::class);
        $def->set('service', fn(ContainerInterface $c): object => $c->get(TestObject::class));

        $container = new Container();

        $this->assertEquals('result', $def->expand('closure', $container));
        $this->assertEquals(TestObject::class, $def->expand('value', $container));
        $this->assertInstanceOf(TestObject::class, $def->expand('service', $container));
    }

    public function testBind(): void
    {
        $def = Definitions::create();
        $def->bind(TestInterface::class, TestObject::class);
        $this->assertTrue($def->has(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get(TestInterface::class));

        // Abstract classes can be bound too
        $def2 = Definitions::create();
        $def2->bind(DefinitionsTestAbstract::class, DefinitionsTestConcrete::class);
        $this->assertEquals(DefinitionsTestConcrete::class, $def2->get(DefinitionsTestAbstract::class));
    }

    public function testReservedIdCannotBeSetOrBound(): void
    {
        $def = Definitions::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved by the container');
        $def->set(ContainerInterface::class, TestObject::class);
    }

    public function testReservedIdCannotBeBound(): void
    {
        $def = Definitions::create();

        $this->expectException(InvalidArgumentException::class);
        $def->bind(ContainerInterface::class, TestObject::class);
    }

    public function testReservedIdCannotReceiveParameters(): void
    {
        $def = Definitions::create();

        $this->expectException(InvalidArgumentException::class);
        $def->parameter(ContainerInterface::class, 'x', 1);
    }

    public function testReservedIdCannotReceiveCallbacks(): void
    {
        $def = Definitions::create();

        $this->expectException(InvalidArgumentException::class);
        $def->callback(ContainerInterface::class, fn() => null);
    }

    public function testParameter(): void
    {
        $def = Definitions::create();
        $def->parameter(TestObject::class, 'key', 'value');
        $this->assertArrayHasKey('key', $def->parametersFor(TestObject::class));
        $this->assertEquals('value', $def->parametersFor(TestObject::class)['key']);
    }

    public function testParameters(): void
    {
        $def = Definitions::create();
        $def->parameters(TestObject::class, param1: 'value1', param2: 'value2');
        $this->assertArrayHasKey('param1', $def->parametersFor(TestObject::class));
        $this->assertArrayHasKey('param2', $def->parametersFor(TestObject::class));
        $this->assertEquals('value1', $def->parametersFor(TestObject::class)['param1']);
        $this->assertEquals('value2', $def->parametersFor(TestObject::class)['param2']);
    }

    public function testAllParametersForGivesPriorityToId(): void
    {
        $def = Definitions::create();
        $def->parameter(TestObject::class, 'shared', 'class');
        $def->parameter(TestInterface::class, 'shared', 'id');
        $def->parameter(TestInterface::class, 'only', 'id');

        $all = $def->allParametersFor(TestObject::class, TestInterface::class);
        $this->assertSame('id', $all['shared']);
        $this->assertSame('id', $all['only']);

        // When class and id are the same, parameters are returned once
        $same = $def->allParametersFor(TestObject::class, TestObject::class);
        $this->assertSame('class', $same['shared']);
    }

    public function testCallback(): void
    {
        $def = Definitions::create();
        $callback1 = fn(): null => null;
        $callback2 = fn(): null => null;
        $def->callback(TestObject::class, $callback1);
        $def->callback(TestObject::class, $callback2, 'test');

        $callbacks = $def->callbacksFor(TestObject::class);
        $this->assertCount(2, $callbacks);
        $this->assertContains($callback1, $callbacks);
        $this->assertSame($callback2, $callbacks['test']);
    }

    public function testNamedCallbackCanBeOverwritten(): void
    {
        $def = Definitions::create();
        $def->callback(TestObject::class, fn() => null, 'named');
        $overwrite = fn() => null;
        $def->callback(TestObject::class, $overwrite, 'named');

        $this->assertCount(1, $def->callbacksFor(TestObject::class));
        $this->assertSame($overwrite, $def->callbacksFor(TestObject::class)['named']);
    }

    public function testLock(): void
    {
        $def = Definitions::create();
        $this->assertFalse($def->isLocked());
        $def->lock();
        $this->assertTrue($def->isLocked());

        // Mutating a locked definitions object throws
        $this->expectException(AssertionError::class);
        $def->set('something', TestObject::class);
    }

    public function testCreateContainer(): void
    {
        $def = Definitions::create();
        $def->set('test', TestObject::class);
        $container = $def->createContainer();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertTrue($container->has('test'));
        $this->assertTrue($def->isLocked());
    }

    public function testCallbacksForClass(): void
    {
        $def = Definitions::create();
        $parentCallback = fn($obj) => $obj;
        $childCallback = fn($obj) => $obj;

        $def->callback(TestApp::class, $parentCallback);
        $def->callback(TestExtendedApp::class, $childCallback);

        $callbacks = $def->callbacksForClass(TestExtendedApp::class);

        $this->assertCount(2, $callbacks);
        $this->assertSame($parentCallback, $callbacks[0]);
        $this->assertSame($childCallback, $callbacks[1]);
    }

    public function testGetValuesAndParameters(): void
    {
        $def = Definitions::create();
        $def->set('z', TestObject::class);
        $def->set('a', TestObject::class);
        $this->assertEquals(['z' => TestObject::class, 'a' => TestObject::class], $def->getValues());

        $def->parameter('z', 'z', 'z');
        $def->parameter('a', 'a', 'a');
        $this->assertEquals(['z' => ['z' => 'z'], 'a' => ['a' => 'a']], $def->getParameters());
    }

    public function testAltInterfaceCanBeRegisteredAsObject(): void
    {
        $obj = new TestObject5('v', 'v2', []);
        $def = Definitions::create([
            TestAltInterface::class => $obj,
            TestObject5::class => $obj,
        ]);

        $this->assertTrue($def->has(TestAltInterface::class));
        $this->assertSame($obj, $def->get(TestAltInterface::class));
        $this->assertSame($obj, $def->get(TestObject5::class));
    }
}
