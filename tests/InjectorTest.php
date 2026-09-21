<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestAltInterface;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject5;
use Kaly\Tests\Mocks\TestObject5Parent;
use Kaly\Tests\Mocks\TestObject6;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class InjectorTest extends TestCase
{
    public function testInjectorCreate(): void
    {
        $injector = new Injector(new Container());
        $inst = $injector->make(TestObject5::class, v: 'test', v2: 'test', arr: []);

        $this->assertInstanceOf(TestObject5::class, $inst);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('test', $inst->v2);
        $this->assertEquals([], $inst->arr);
        $this->assertNull($inst->v3); // its nullable

        // You can also use ...array if you don't like named arguments
        $inst = $injector->make(TestObject5::class, ...['v' => 'test', 'v2' => 'test', 'arr' => []]);
        $this->assertInstanceOf(TestObject5::class, $inst);

        $definitions = Definitions::create()
            ->parameter(TestObject5::class, 'v', 'from definitions')
            ->parameter(TestObject5::class, 'v2', 'from definitions v2')
            ->parameter(TestObject5::class, 'arr', [])
            ->lock();

        $container = new Container($definitions);
        $injectorContainer = new Injector($container);

        $instFromContainer = $container->get(TestObject5::class);
        $this->assertEquals('from definitions', $instFromContainer->v);
        $this->assertNull($instFromContainer->v3);

        // if object has been created by container, the injector will use it
        $fn = fn(TestObject5 $a): TestObject5 => $a;
        $this->assertSame($instFromContainer, $injectorContainer->invoke($fn));

        // if we make a parent class, the unprovided object dependency comes from the container
        $instWithoutParam = $injectorContainer->make(TestObject5Parent::class);
        $this->assertSame($instFromContainer, $instWithoutParam->v);

        // With a container that cannot build TestObject5, this fails with a clear exception
        $this->expectException(UnresolvableParameterException::class);
        (new Injector(new Container()))->invoke($fn);
    }

    public function testInjectorTypes(): void
    {
        $injector = new Injector(new Container());
        $fn = fn(): string => 'test';
        $this->assertEquals('test', $injector->invoke($fn));

        // required built-in params throw a typed exception
        $fn = fn(string $a): string => $a;
        $this->expectException(UnresolvableParameterException::class);
        $injector->invoke($fn);
    }

    public function testInvokeResolvesContainerInterfaceParameter(): void
    {
        $container = new Container();
        $injector = new Injector($container);

        $resolved = $injector->invoke(fn(ContainerInterface $c): ContainerInterface => $c);

        $this->assertSame($container, $resolved);
    }

    public function testInjectorArgumentPassing(): void
    {
        $injector = new Injector(new Container());

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

        // you can use ...spread syntax (named, positional)
        $fn = fn(string $a): string => $a;
        $this->assertEquals('test', $injector->invoke($fn, ...[
            'a' => 'test',
        ]));
        $this->assertEquals('test', $injector->invoke($fn, ...[
            'test',
        ]));

        // complex types
        $fn = fn(string|bool $a): string|bool => $a;
        $this->assertTrue($injector->invoke($fn, true));
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
        $injector->invoke($fn, a: true);
    }

    public function testInjectorMixedPositionalAndNamedArguments(): void
    {
        $injector = new Injector(new Container());
        $fn = fn(string $a = 'default', string $b = 'B'): string => $a . $b;

        // Positional + named in one call: both must land on their parameter
        // (the positional used to be silently dropped)
        $this->assertEquals('ab', $injector->invoke($fn, 'a', b: 'b'));

        // Same for make()
        $inst = $injector->make(TestObject5::class, 'test', v2: 'other', arr: []);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('other', $inst->v2);
    }

    public function testInvokeRejectsUnknownNamedArgument(): void
    {
        $injector = new Injector(new Container());
        $fn = fn(string $a): string => $a;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown named argument\(s\): `b`/');
        $injector->invoke($fn, a: 'x', b: 'y');
    }

    public function testInvokeRejectsSurplusPositionalArguments(): void
    {
        $injector = new Injector(new Container());
        $fn = fn(string $a, string $b): string => $a . $b;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Too many positional arguments/');
        $injector->invoke($fn, 'a', 'b', 'c');
    }

    public function testInvokeRejectsDoubleAssignment(): void
    {
        $injector = new Injector(new Container());
        $fn = fn(string $a): string => $a;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both positionally/');
        $injector->invoke($fn, 'x', a: 'y');
    }

    public function testMakeInterfaceThrows(): void
    {
        $injector = new Injector(new Container());
        $this->expectException(InvalidArgumentException::class);
        $injector->make(TestInterface::class);
    }

    public function testMakeNonExistentClassThrows(): void
    {
        $injector = new Injector(new Container());
        $this->expectException(InvalidArgumentException::class);
        $injector->make('NonExistentClass');
    }

    public function testMakeAlwaysReturnsFreshInstances(): void
    {
        $container = new Container();
        $injector = new Injector($container);

        $first = $injector->make(TestObject::class);
        $second = $injector->make(TestObject::class);

        $this->assertNotSame($first, $second);

        // make() does not populate the container cache
        $this->assertNotSame($first, $container->get(TestObject::class));
    }

    public function testMakeResolvesObjectDependenciesFromContainer(): void
    {
        $container = new Container(Definitions::create()->bind(TestInterface::class, TestObject::class));
        $injector = new Injector($container);

        $inst = $injector->make(TestObject6::class, v: 'a', v2: 'b', arr: []);

        $this->assertInstanceOf(TestObject6::class, $inst);
    }

    /**
     * make() is a root-level constructor, not an independent recursive autowirer:
     * a missing object dependency is resolved through the PSR-11 container only,
     * without a Kaly-style recursive fallback.
     */
    public function testMakeDoesNotRecursivelyAutowireWithAStrictPsr11Container(): void
    {
        $strict = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $injector = new Injector($strict);

        try {
            $injector->make(TestObject5Parent::class);
            $this->fail('Expected an UnresolvableParameterException');
        } catch (UnresolvableParameterException $e) {
            $this->assertSame('v', $e->getParameterName());
            $this->assertStringContainsString(TestObject5::class, $e->getMessage());
        }
    }

    public function testMakeDoesNotUseContainerDefinitionsForTheRootClass(): void
    {
        $container = new Container(
            Definitions::create()
                ->parameter(TestObject5::class, 'v', 'from definitions')
                ->parameter(TestObject5::class, 'v2', 'from definitions v2')
                ->parameter(TestObject5::class, 'arr', [])
                ->lock(),
        );
        $injector = new Injector($container);

        // explicit arguments win, definitions are not consumed by make()
        $inst = $injector->make(TestObject5::class, v: 'explicit', v2: 'x', arr: []);
        $this->assertEquals('explicit', $inst->v);

        // definitions do not fill missing root arguments either
        $this->expectException(UnresolvableParameterException::class);
        $injector->make(TestObject5::class);
    }

    /**
     * Test calling make with spread arrays
     */
    public function testMakeWithSpread(): void
    {
        $injector = new Injector(new Container());
        $inst = $injector->make(TestObject5::class, ...['v' => 'test', 'v2' => 'test2', 'arr' => ['a']]);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('test2', $inst->v2);
        $this->assertEquals(['a'], $inst->arr);

        // positional should work
        $inst2 = $injector->make(TestObject5::class, ...['test', 'test2', ['b']]);
        $this->assertEquals('test', $inst2->v);
        $this->assertEquals('test2', $inst2->v2);
        $this->assertEquals(['b'], $inst2->arr);
    }

    public function testMakeThrowsWhenPassingNonArrayToNamedVariadicInjector(): void
    {
        $injector = new Injector(new Container());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be an array when passed by name/');
        $injector->invoke(function (...$names) {}, names: 'not-an-array');
    }

    public function testInvokeNamedVariadicWithAssociativeKeys(): void
    {
        $injector = new Injector(new Container());

        $this->assertSame(['a', 'b'], $injector->invoke(fn(string ...$names): array => $names, names: [
            'first' => 'a',
            'second' => 'b',
        ]));
    }

    public function testClosureCaching(): void
    {
        $injector = new Injector(new Container());

        $f1 = function (int $a) {
            return $a;
        };
        $f2 = function (string $b) {
            return $b;
        };

        $this->assertEquals(1, $injector->invoke($f1, a: 1));
        $this->assertEquals('test', $injector->invoke($f2, b: 'test'));

        // Re-invoke to ensure cache doesn't break things
        $this->assertEquals(2, $injector->invoke($f1, a: 2));
        $this->assertEquals('other', $injector->invoke($f2, b: 'other'));
    }

    public function testInvokeNonClosureCallable(): void
    {
        $injector = new Injector(new Container());
        $object = new class {
            public function greet(string $name): string
            {
                return 'hello ' . $name;
            }

            public static function staticGreet(string $name): string
            {
                return 'static ' . $name;
            }
        };

        $this->assertEquals('hello bob', $injector->invoke([$object, 'greet'], name: 'bob'));
        $this->assertEquals('static bob', $injector->invoke([$object::class, 'staticGreet'], name: 'bob'));
    }
}
