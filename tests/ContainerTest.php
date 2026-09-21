<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestAltInterface;
use Kaly\Tests\Mocks\TestApp;
use Kaly\Tests\Mocks\TestExceptionConstructorObject;
use Kaly\Tests\Mocks\TestExtendedApp;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestNullableArg;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject4;
use Kaly\Tests\Mocks\TestObject6;
use Kaly\Tests\Mocks\TestObjectA;
use Kaly\Tests\Mocks\TestObjectSub;
use Kaly\Tests\Mocks\TestObjectTwoPdosVal;
use Kaly\Tests\Mocks\TestUnionClass;
use Kaly\Tests\Mocks\TestVariadicArg;
use Kaly\Tests\Mocks\TestZIntersectionClass;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

class ContainerTest extends TestCase
{
    /**
     * A container can create any concrete class even if not defined in the definitions
     */
    public function testItCanCreateObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testItReturnsTheSameInstanceForTheSameId(): void
    {
        $di = new Container();
        $first = $di->get(TestObject::class);
        $second = $di->get(TestObject::class);
        $this->assertSame($first, $second);
    }

    public function testItCanCreateUnionObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestUnionClass::class);
        $this->assertInstanceOf(TestUnionClass::class, $inst);
        // string|TestObject gets resolved to a TestObject
        $this->assertInstanceOf(TestObject::class, $inst->v);
    }

    public function testItCanCreateIntersectionObject(): void
    {
        $di = new Container(
            Definitions::create()
                ->parameter(TestObject6::class, 'v', 'test')
                ->parameter(TestObject6::class, 'v2', 'test2')
                ->parameter(TestObject6::class, 'arr', [])
                ->parameter(
                    TestZIntersectionClass::class,
                    'v',
                    fn(ContainerInterface $c) => $c->get(TestObject6::class),
                )
                ->lock(),
        );
        $inst = $di->get(TestZIntersectionClass::class);
        $this->assertInstanceOf(TestZIntersectionClass::class, $inst);
        $this->assertInstanceOf(TestObject6::class, $inst->v);
    }

    public function testItFailsToCreateIntersectionObjectWithoutParameter(): void
    {
        $di = new Container();
        $this->expectException(UnresolvableParameterException::class);
        $di->get(TestZIntersectionClass::class);
    }

    public function testItCanCreateNullableObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestNullableArg::class);

        $this->assertInstanceOf(TestNullableArg::class, $inst);
        $this->assertNull($inst->test);
    }

    public function testItCanCreateObjectWithVariadicConstructor(): void
    {
        // Without definitions, variadic arguments are simply empty
        $di = new Container();
        $inst = $di->get(TestVariadicArg::class);
        $this->assertInstanceOf(TestVariadicArg::class, $inst);
        $this->assertEquals([], $inst->names);

        // With definitions, the array is provided to the variadic parameter
        $diWithParams = new Container(
            Definitions::create()->parameter(TestVariadicArg::class, 'names', ['a', 'b'])->lock(),
        );
        $instWithParams = $diWithParams->get(TestVariadicArg::class);
        $this->assertEquals(['a', 'b'], $instWithParams->names);
    }

    public function testItThrowsWhenPassingNonArrayToNamedVariadicContainer(): void
    {
        $di = new Container(Definitions::create()->parameter(TestVariadicArg::class, 'names', 'not-an-array')->lock());

        $this->expectException(ContainerException::class);
        try {
            $di->get(TestVariadicArg::class);
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Unable to create object', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertStringContainsString('must be an array when passed by name', $e->getPrevious()->getMessage());
            throw $e;
        }
    }

    /**
     * stdClass are a bit special, they are not built in and the container
     * should return a simple stdClass each time
     */
    public function testItCanCreateStdClass(): void
    {
        $di = new Container();
        $inst = $di->get(stdClass::class);
        $this->assertInstanceOf(stdClass::class, $inst);

        // You cannot use stdClass as id
        $this->expectException(DefinitionException::class);
        $di = new Container([
            stdClass::class => TestObject::class,
        ]);
    }

    /**
     * A container can instantiate a bound interface.
     * The interface is the 'id' in the container.
     */
    public function testItCanCreateInterface(): void
    {
        $di = new Container(Definitions::create()->bind(TestInterface::class, TestObject::class));

        // You can get by interface
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);

        // Or by class
        $inst2 = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst2);

        // It can use a simple array mapping
        $di = new Container([
            TestInterface::class => TestObject::class,
        ]);
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);
    }

    public function testItCannotGetAnUnboundInterface(): void
    {
        $di = new Container();
        $this->assertFalse($di->has(TestInterface::class));
        $this->expectException(ReferenceNotFoundException::class);
        $di->get(TestInterface::class);
    }

    /**
     * This should really not happen in practical cases, but if it does
     * we make sure to throw a proper exception
     */
    public function testItCanDetectCircularDependancies(): void
    {
        $di = new Container();
        $this->expectException(CircularReferenceException::class);

        // A depends on B which depends on A
        $di->get(TestObjectA::class);
    }

    /**
     * Definitions can contain factories. With this, classes are only instantiated/configured
     * if used within the request, and only once (factories are shared through get()).
     */
    public function testItBuildsLazilyAndSharesFactoryResult(): void
    {
        // Counter is incremented when constructing the object
        TestObject2::$counter = 0;
        $di = new Container(Definitions::create()->set(
            TestObject2::class,
            fn(): TestObject2 => new TestObject2('lazy'),
        ));

        // Definitions are set, but lazy factory is not yet called
        $this->assertEquals(0, TestObject2::$counter);

        // Object is constructed, counter = 1
        $inst = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);

        // Object is retrieved from cache, counter = 1
        $inst2 = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);
        $this->assertSame($inst, $inst2);

        // Reset for other tests
        TestObject2::$counter = 0;
    }

    /**
     * You can register a true instance directly
     */
    public function testItCanUseObjectDefinitions(): void
    {
        $app = new TestExtendedApp(__DIR__);
        $di = new Container(Definitions::create()->set(TestExtendedApp::class, $app)->set(TestApp::class, $app));

        $this->assertSame($app, $di->get(TestExtendedApp::class));
        $this->assertSame($app, $di->get(TestApp::class));
    }

    public function testItDoesNotAutoWireItsOwnInternals(): void
    {
        $di = new Container();

        $this->assertFalse($di->has(Container::class));
        $this->assertFalse($di->has(Definitions::class));

        $this->expectException(ReferenceNotFoundException::class);
        $di->get(Container::class);
    }

    public function testGetDefinitionsThrowsNotFound(): void
    {
        $di = new Container();
        $this->expectException(ReferenceNotFoundException::class);
        $di->get(Definitions::class);
    }

    /**
     * Has returns true if it's a valid concrete class, a bound abstraction or a registered id
     */
    public function testHasMethod(): void
    {
        $di = new Container();
        $this->assertTrue($di->has(TestObject::class));
        $this->assertFalse($di->has(TestInterface::class));

        $di2 = new Container(Definitions::create()->set(TestObject::class, new TestObject()));
        $this->assertTrue($di2->has(TestObject::class));

        $di3 = new Container(Definitions::create()->bind(TestInterface::class, TestObject::class));
        $this->assertTrue($di3->has(TestInterface::class));

        $di4 = new Container(Definitions::create()->set('custom', TestObject::class));
        $this->assertTrue($di4->has('custom'));
        $this->assertFalse($di4->has('unknown'));
    }

    /**
     * It throws exception if trying to get an unknown class
     */
    public function testGetNotFound(): void
    {
        $di = new Container();

        $this->assertFalse($di->has('NotFoundClass'));

        $this->expectException(ReferenceNotFoundException::class);
        $di->get('NotFoundClass');
    }

    /**
     * It throws an UnresolvableParameterException if the parameter is missing from the container
     */
    public function testBuildMissingParameter(): void
    {
        $this->expectException(UnresolvableParameterException::class);
        $di = new Container();
        $di->get(PDO::class);
    }

    public function testUnknownConfiguredParameterIsRejected(): void
    {
        $di = new Container(Definitions::create()->parameter(TestObject2::class, 'v2', 'x'));

        try {
            $di->get(TestObject2::class);
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('v2', $e->getMessage());
            $this->assertStringContainsString('Available', $e->getMessage());
            $this->assertStringContainsString('`v`', $e->getMessage());
        }
    }

    public function testUnknownConfiguredParameterIsRejectedBeforeParameterClosuresRun(): void
    {
        $called = false;
        $mark = function () use (&$called): string {
            $called = true;
            return 'value';
        };
        $di = new Container(
            Definitions::create()
                ->parameter(TestObject2::class, 'v', $mark)
                ->parameter(TestObject2::class, 'typo', $mark),
        );

        try {
            $di->get(TestObject2::class);
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('typo', $e->getMessage());
        }

        $this->assertFalse($called);
    }

    /**
     * Exceptions thrown during the build process are wrapped in a ContainerException
     */
    public function testBuildError(): void
    {
        $this->expectException(ContainerException::class);
        $di = new Container();
        $di->get(TestExceptionConstructorObject::class);
    }

    /**
     * You can specify parameters in multiple manners: one by one or with named arguments
     */
    public function testBuildWithParameters(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $di = new Container(
            Definitions::create()
                ->parameter(TestObject4::class, 'pdo', $pdo)
                ->parameter(TestObject4::class, 'bar', 'bar-value'),
        );
        $inst = $di->get(TestObject4::class);
        $this->assertInstanceOf(TestObject4::class, $inst);
        $this->assertSame('bar-value', $inst->bar);

        $definitions = Definitions::create()
            // lazy pdo
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            // parameters array
            ->parameters(TestObject4::class, bar: 'bar-value', baz: 'baz-value', arr: ['a', 'b', 'c']);

        $di = new Container($definitions);
        $inst = $di->get(TestObject4::class);
        $this->assertInstanceOf(TestObject4::class, $inst);
        $this->assertSame('bar-value', $inst->bar);
        $this->assertSame('baz-value', $inst->baz);
        $this->assertSame(['a', 'b', 'c'], $inst->arr);
    }

    /**
     * When a class takes two objects of the same type, use a parameter that
     * returns the right service from the container.
     */
    public function testBuildWithMultipleServicesOfSameType(): void
    {
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDb', fn(): \PDO => new PDO('sqlite::memory:'))
            ->parameter(TestObjectTwoPdosVal::class, 'backupDb', fn(ContainerInterface $c) => $c->get('backupDb'))
            ->lock();

        $di = new Container($definitions);
        $inst = $di->get(TestObjectTwoPdosVal::class);
        $pdo = $di->get(PDO::class);
        $backupDb = $di->get('backupDb');

        $this->assertInstanceOf(TestObjectTwoPdosVal::class, $inst);
        $this->assertInstanceOf(PDO::class, $inst->db);
        $this->assertInstanceOf(PDO::class, $inst->backupDb);
        $this->assertNotSame($pdo, $backupDb);
        $this->assertSame($pdo, $inst->db);
        $this->assertSame($backupDb, $inst->backupDb);
    }

    /**
     * You can run extra code after object instantiation.
     * This only happens once because instances are cached.
     */
    public function testBuildWithCallbacks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $definitions = Definitions::create()
            ->set(PDO::class, $pdo)
            ->parameter(TestObject4::class, 'bar', 'bar-value')
            ->parameter(TestObject4::class, 'baz', 'baz-value')
            ->parameter(TestObject4::class, 'arr', ['a', 'b', 'c'])
            ->callback(TestObject4::class, function (TestObject4 $obj): void {
                $obj->testMethod('test');
            });

        $di = new Container($definitions);
        $inst = $di->get(TestObject4::class);
        $this->assertSame(['test'], $inst->test);

        // Make sure it's run only once.
        $calls = 0;
        $definitions = Definitions::create()
            ->parameter(TestObject2::class, 'v', 'initial')
            ->callback(TestObject2::class, function (TestObject2 $obj) use (&$calls): void {
                $calls++;
                $obj->v = 'value is ' . $calls;
            });

        $di = new Container($definitions);
        $inst = $di->get(TestObject2::class);
        $inst2 = $di->get(TestObject2::class);
        $this->assertSame(1, $calls);
        $this->assertSame('value is 1', $inst->v);
        $this->assertSame('value is 1', $inst2->v);

        TestObject2::$counter = 0;
    }

    /**
     * Interface callbacks run before the concrete class callbacks.
     */
    public function testBuildWithInterfaceCallbacks(): void
    {
        $definitions = Definitions::create()
            ->bind(TestInterface::class, TestObject::class)
            ->callback(TestInterface::class, function (TestInterface $obj): void {
                $obj->setVal('interface');
            })
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal($obj->getVal() . '+class');
            });

        $di = new Container($definitions);
        $inst = $di->get(TestInterface::class);
        $this->assertSame('interface+class', $inst->getVal());
    }

    /**
     * Base class callbacks run before subclass callbacks.
     */
    public function testBuildWithSubclassesCallbacks(): void
    {
        $definitions = Definitions::create()->callback(TestObject::class, function (TestObject $obj): void {
            $obj->setVal('baz');
        });

        $di = new Container($definitions);

        // Subclass also gets the parent value
        $inst = $di->get(TestObjectSub::class);
        $this->assertEmpty($definitions->callbacksFor(TestObjectSub::class));
        $this->assertCount(1, $definitions->callbacksForClass(TestObjectSub::class));
        $this->assertSame('baz', $inst->getVal());

        $definitions = Definitions::create()
            ->callback(TestObjectSub::class, function (TestObjectSub $obj): void {
                $this->assertEquals('baz', $obj->getVal()); // it was set before
                $obj->setVal('bar');
            })
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('baz'); // this is overwritten by subclass
            });

        $di = new Container($definitions);
        $inst = $di->get(TestObjectSub::class);
        $this->assertSame('bar', $inst->getVal());
    }

    /**
     * Callbacks can be registered for a specific service id.
     */
    public function testBuildWithServicesCallbacks(): void
    {
        $definitions = Definitions::create()
            ->set('custom', TestObject::class)
            ->callback('custom', function (TestObject $obj): void {
                $this->assertEquals('class', $obj->getVal()); // set by the class callback
                $obj->setVal('service');
            })
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('class');
            });

        $di = new Container($definitions);
        $this->assertSame('class', $di->get(TestObject::class)->getVal());
        $this->assertSame('service', $di->get('custom')->getVal());
    }

    /**
     * When naming a callback, we can override it later if needed
     */
    public function testNamedCallback(): void
    {
        $definitions = Definitions::create()->callback(
            TestObject::class,
            function (TestObject $obj): void {
                $obj->setVal('first');
            },
            'my_callback',
        );

        // later in another galaxy far far away
        $definitions->callback(
            TestObject::class,
            function (TestObject $obj): void {
                $obj->setVal('second');
            },
            'my_callback',
        );

        $di = new Container($definitions);
        $inst = $di->get(TestObject::class);
        $this->assertCount(1, $definitions->callbacksFor(TestObject::class));
        $this->assertSame('second', $inst->getVal());
    }

    public function testAltInterfaceRemainsDistinct(): void
    {
        $definitions = Definitions::create()
            ->bind(TestInterface::class, TestObject6::class)
            ->bind(TestAltInterface::class, TestObject6::class)
            ->parameters(TestObject6::class, v: 'v', v2: 'v2', arr: []);
        $di = new Container($definitions);

        $first = $di->get(TestInterface::class);
        $alt = $di->get(TestAltInterface::class);

        // Cached by service id, so two distinct instances of the same class
        $this->assertNotSame($first, $alt);
        $this->assertEquals($first, $alt);
    }
}
