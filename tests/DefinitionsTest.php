<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject5;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestAltInterface;

class DefinitionsTest extends TestCase
{
    public function testCreateAndMerge(): void
    {
        $arr = [
            TestInterface::class => TestObject::class
        ];

        $def = new Definitions($arr);
        $def2 = Definitions::create($arr);

        $this->assertEquals($def2, $def);
        $this->assertTrue($def2 !== $def);

        $this->assertTrue($def->has(TestInterface::class));
        $this->assertFalse($def->miss(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get(TestInterface::class));

        $obj = new TestObject5('v', 'v2', []);
        $def->add($obj);
        $this->assertTrue($def->has(TestAltInterface::class));
        $this->assertTrue($def->has(TestObject5::class));

        $def->lock();
        $this->assertTrue($def->isLocked());

        // Throws assert errors afterwards
        $this->expectException(AssertionError::class);
        $def->set("something", "something");
    }

    public function testMergeDefinitions(): void
    {
        $def1 = Definitions::create()->set('obj', TestObject::class);
        $def2 = Definitions::create()->set('obj2', TestObject2::class);

        $this->assertTrue($def1->has('obj'));
        $this->assertTrue($def2->has('obj2'));

        $def1->parameter(TestObject5::class, 'v', 'provided_value');
        $def1->callback(TestObject::class, fn($obj) => $obj);
        $def1->resolve(TestObject::class, 'v', fn($k) => $k);

        // parameters can come from multiple source, the latest to be merged will overwrite any existing param
        $def2->parameter(TestObject5::class, 'v2', 'provided value');

        $final = new Definitions($def1);
        $final->merge($def2);

        $this->assertTrue($final->has('obj'));
        $this->assertTrue($final->has('obj2'));
        $this->assertArrayHasKey(TestObject5::class, $final->getParameters());
        $this->assertArrayHasKey('v', $final->getParameters()[TestObject5::class]);
        $this->assertArrayHasKey('v2', $final->getParameters()[TestObject5::class]);
        $this->assertArrayHasKey(TestObject::class, $final->getCallbacks());
        $this->assertArrayHasKey(TestObject::class, $final->getResolvers());
    }

    public function testGetAndSet(): void
    {
        $def = Definitions::create();
        $def->set('key', TestObject::class);
        $this->assertTrue($def->has('key'));
        $this->assertEquals(TestObject::class, $def->get('key'));
        $this->assertNull($def->get('nonexistent'));
        $this->assertTrue($def->miss('nonexistent'));

        // Already sets, so does nothing
        $def->setDefault('key', TestObject2::class);
        $this->assertEquals(TestObject::class, $def->get('key'));

        // Not set, so sets a value
        $def->setDefault('newkey', TestObject2::class);
        $this->assertEquals(TestObject2::class, $def->get('newkey'));
    }

    public function testExpand(): void
    {
        $def = Definitions::create();
        $def->set('closure', fn(): string => 'result');
        // The closure accepts a Definitions object and an array of parameters
        // This can be used to use other defined services from within the closures to build relevant objects
        $def->set('closure_params', fn(Definitions $def, array $parameters): string => 'result ' . $parameters['my_param']);
        $def->parameter('closure_params', 'my_param', 'my_value');
        $def->set('value', TestObject::class);

        $this->assertEquals('result', $def->expand('closure'));
        $this->assertEquals('result my_value', $def->expand('closure_params'));
        $this->assertEquals(TestObject::class, $def->expand('value'));
    }

    public function testAdd(): void
    {
        $def = Definitions::create();
        $obj = new TestObject5('v', 'v2', []);
        $def->add($obj);

        $this->assertTrue($def->has(TestObject5::class));
        $this->assertTrue($def->has(TestAltInterface::class));
        $this->assertTrue($def->get(TestAltInterface::class) === $obj);
        $this->assertTrue($def->get(TestObject5::class) === $obj);
    }

    public function testBind(): void
    {
        $def = Definitions::create();
        $def->bind(TestObject::class);
        $this->assertTrue($def->has(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get(TestInterface::class));

        // two definitions can be equals
        $def2 = Definitions::create();
        $def2->bind(TestObject::class, TestInterface::class);
        $this->assertEquals($def, $def2);

        // using parameters
        $def3 = Definitions::create();
        $def3->bind(TestObject2::class, TestInterface::class, ['v' => 'test']);
        $this->assertEquals(['v' => 'test'], $def3->parametersFor(TestObject2::class));
    }

    public function testResolve(): void
    {
        $def = Definitions::create();
        $def->resolve(TestObject::class, 'key', 'value');
        $this->assertArrayHasKey('key', $def->resolversFor(TestObject::class));
        $this->assertEquals('value', $def->resolversFor(TestObject::class)['key']);
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

        $def->parameters(TestObject::class, 'value3', 'value4');
        $this->assertEquals('value3', $def->parametersFor(TestObject::class)[0]);
        $this->assertEquals('value4', $def->parametersFor(TestObject::class)[1]);

        $allParams = $def->allParametersFor(TestObject::class);
        $this->assertCount(4, $allParams);
        $this->assertEquals('value3', $allParams[0]);
    }

    public function testCallback(): void
    {
        $def = Definitions::create();
        $def->callback(TestObject::class, fn(): null => null);
        $this->assertArrayHasKey(0, $def->callbacksFor(TestObject::class));
        $def->callback(TestObject::class, fn(): null => null, 'test');
        $this->assertArrayHasKey('test', $def->callbacksFor(TestObject::class));
    }

    public function testLock(): void
    {
        $def = Definitions::create();
        $this->assertFalse($def->isLocked());
        $def->lock();
        $this->assertTrue($def->isLocked());
        $def->unlock();
        $this->assertFalse($def->isLocked());
    }

    public function testSetAll(): void
    {
        $def = Definitions::create();
        $def->setAll([
            'key1' => TestObject::class,
            'key2' => TestObject::class,
        ]);
        $this->assertTrue($def->has('key1'));
        $this->assertTrue($def->has('key2'));
        $this->assertEquals(TestObject::class, $def->get('key1'));
        $this->assertEquals(TestObject::class, $def->get('key2'));
    }

    public function testSort(): void
    {
        $closure = fn(): null => null;
        $def = Definitions::create();
        $def->set('z', TestObject::class);
        $def->set('a', TestObject::class);
        $def->parameter('z', 'z', 'z');
        $def->parameter('a', 'a', 'a');
        $def->callback('z', $closure);
        $def->callback('a', $closure);
        $def->resolve('z', 'z', $closure);
        $def->resolve('a', 'a', $closure);
        $def->sort();

        $this->assertEquals(['a' => TestObject::class, 'z' => TestObject::class], $def->getValues());
        $this->assertEquals(['a' => ['0' => $closure], 'z' => ['0' => $closure]], $def->getCallbacks());
        $this->assertEquals(['a' => ['a' => 'a'], 'z' => ['z' => 'z']], $def->getParameters());
        $this->assertEquals(['a' => ['a' => $closure], 'z' => ['z' => $closure]], $def->getResolvers());
    }

    public function testGetValues(): void
    {
        $def = Definitions::create();
        $def->set('z', TestObject::class);
        $def->set('a', TestObject::class);
        $this->assertEquals(['z' => TestObject::class, 'a' => TestObject::class], $def->getValues());
    }

    public function testGetCallbacks(): void
    {
        $closure = fn(): null => null;
        $def = Definitions::create();
        $def->callback('z', $closure);
        $def->callback('a', $closure);
        $this->assertEquals([
            'z' => ['0' => $closure],
            'a' => ['0' => $closure]
        ], $def->getCallbacks());
    }

    public function testGetParameters(): void
    {
        $def = Definitions::create();
        $def->parameter('z', 'z', 'z');
        $def->parameter('a', 'a', 'a');
        $this->assertEquals(['z' => ['z' => 'z'], 'a' => ['a' => 'a']], $def->getParameters());
    }

    public function testGetResolvers(): void
    {
        $closure = fn(): null => null;
        $def = Definitions::create();
        $def->resolve('z', 'z', $closure);
        $def->resolve('a', 'a', $closure);
        $this->assertEquals([
            'z' => ['z' => $closure],
            'a' => ['a' => $closure]
        ], $def->getResolvers());
    }
}
