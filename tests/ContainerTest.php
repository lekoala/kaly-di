<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Tests\Mocks\TestApp;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObjectA;
use Kaly\Di\Injector;
use Kaly\Tests\Mocks\TestExtendedApp;
use Kaly\Tests\Mocks\TestObject4;
use PDO;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Di\ContainerException;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestAltInterface;
use stdClass;
use Kaly\Tests\Mocks\TestExceptionConstructorObject;
use Kaly\Tests\Mocks\TestObject3;
use Kaly\Tests\Mocks\TestObject6;
use Kaly\Tests\Mocks\TestObjectTwoPdosInterface;
use Kaly\Tests\Mocks\TestObjectTwoPdosVal;
use Kaly\Tests\Mocks\TestObjectSub;
use Kaly\Tests\Mocks\TestUnionClass;
use Kaly\Tests\Mocks\TestZIntersectionClass;
use Kaly\Tests\Mocks\TestObjectSinglePdoVal;

class ContainerTest extends TestCase
{
    /**
     * A container can create any existing class even if not defined in the definitions
     */
    public function testItCanCreateObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    /**
     * When resolving an union class, we always try to use the more "complex" type
     */
    public function testItCanCreateUnionObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestUnionClass::class);
        $this->assertInstanceOf(TestUnionClass::class, $inst);
        // string|TestObject gets resolve to a TestObject
        $this->assertInstanceOf(TestObject::class, $inst->v);
    }

    /**
     * When resolving an intersection class, you need to use parameters
     */
    public function testItCanCreateIntersectionObject(): void
    {
        $di = new Container(Definitions::create()
            ->bindAll(TestObject6::class)
            ->containerParameter(TestZIntersectionClass::class, 'v', TestObject6::class)
            // Same as
            // ->parameter(TestZIntersectionClass::class, 'v', new ServiceName(TestObject6::class))
            ->lock());
        $inst = $di->get(TestZIntersectionClass::class);
        $this->assertInstanceOf(TestZIntersectionClass::class, $inst);
        $this->assertInstanceOf(TestObject6::class, $inst->v);

        $firstInterface = $di->get(TestInterface::class);
        $altInterface = $di->get(TestAltInterface::class);

        // Objects are not the same (they are cached by service id, ie: their interface)
        $this->assertNotSame($firstInterface, $altInterface);
        // They have the same class (TestObject6)
        $this->assertEquals($firstInterface, $altInterface);
        // Intersection is resolved using the first of the interface
        // $this->assertNotSame($firstInterface, $inst->v);
        // $this->assertSame($altInterface, $inst->v);
        $this->assertNotSame($firstInterface, $inst->v);
        $this->assertNotSame($altInterface, $inst->v);
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
        $this->expectException(AssertionError::class);
        $di = new Container([
            stdClass::class => TestObject::class
        ]);
    }

    /**
     * A container can instantiate a bound interface
     * The interface is the 'id' in the container
     * A given interface can only be bound to one single class
     */
    public function testItCanCreateInterface(): void
    {
        $di = new Container(
            Definitions::create()
                ->bind(TestObject::class) // autobinds since there is only one interface
        );

        // You can get by interface
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);

        // Or by class
        $inst2 = $di->get(TestObject::class);
        $this->assertEquals($inst, $inst2); // it's the same object

        // It can use a simple array mapping
        $di = new Container([
            TestInterface::class => TestObject::class
        ]);
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);
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
        $inst = $di->get(TestObjectA::class);
        $this->assertEmpty($inst);
    }

    /**
     * Definitions can contain factories. With this, class are only instantiated/configured
     * if used within the request
     */
    public function testItBuildsLazily(): void
    {
        // Counter is incremented when constructing the object
        TestObject2::$counter = 0;
        $di = new Container(
            Definitions::create()
                ->set(TestObject2::class, fn(): TestObject2 => new TestObject2("lazy"))
        );

        // Definitions are set, but lazy factory is not yet called
        $this->assertEquals(0, TestObject2::$counter);

        // Object is constructed, counter = 1
        $inst = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);

        // Object is retrieved from cache, counter = 1
        $inst2 = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);
        $this->assertEquals($inst, $inst2);

        // Reset for other tests
        TestObject2::$counter = 0;
    }

    /**
     * You can also register a true instance directly
     */
    public function testItCanRegisterInstance(): void
    {
        $app = new TestExtendedApp(__DIR__);
        $di = new Container(
            Definitions::create()
                ->add($app) // Calling add make sure you bind the class to all its interfaces/subclasses as well
        );

        // Get by exact class name
        $this->assertEquals($app, $di->get(TestExtendedApp::class));

        // Get by parent class
        $this->assertEquals($app, $di->get(TestApp::class));
    }

    /**
     * Not sure why you would want to do that, but just in case...
     */
    public function testItReturnsSelfWhenAskedForContainer(): void
    {
        $di = new Container();
        $this->assertEquals($di, $di->get(Container::class));
    }

    /**
     * If you want an injector, returns one that knows about the container
     */
    public function testItReturnsInjector(): void
    {
        $di = new Container();
        $injector = $di->get(Injector::class);
        $this->assertInstanceOf(Injector::class, $injector);

        // You can still define your own if you want
        $customInjector = new Injector();
        $di = new Container([
            Injector::class => $customInjector
        ]);
        $injector = $di->get(Injector::class);
        $this->assertEquals($customInjector, $injector);
    }

    /**
     * Has returns true if it's a valid class or a registered id
     */
    public function testHasMethod(): void
    {
        $di = new Container();
        $this->assertTrue($di->has(TestObject::class));

        $di2 = new Container(Definitions::create()->set(TestObject::class, new TestObject()));
        $this->assertTrue($di2->has(TestObject::class));

        $di3 = new Container(Definitions::create()->set(TestInterface::class, TestObject::class));
        $this->assertTrue($di3->has(TestInterface::class));

        $di4 = new Container(Definitions::create()->set('custom', TestObject::class));
        $this->assertTrue($di4->has('custom'));
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
     * IT throws an UnresolvableParameterException if the parameter is missing from the container
     */
    public function testBuildMissingParameter(): void
    {
        // This doesn't throw UnresolvableParameterException because we don't know that '' is an invalid dsn
        $this->expectException(ContainerException::class);
        $di = new Container();
        $di->get(PDO::class);

        // This however does properly throw the custom exception
        $this->expectException(UnresolvableParameterException::class);
        $di = new Container();
        $di->get(TestObject3::class);
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
     * You can specify parameters in multiple manners
     * One by one
     * Using named arguments
     * Using an array
     */
    public function testBuildWithParameters(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $di = new Container(
            Definitions::create()
                // you can also simply call ->parameters with named params
                ->parameter(TestObject4::class, 'pdo', $pdo)
                ->parameter(TestObject4::class, 'bar', 'bar-value')
        );
        $inst = $di->get(TestObject4::class);
        $this->assertInstanceOf(TestObject4::class, $inst);
        $this->assertSame('bar-value', $inst->bar);


        $definitions = Definitions::create()
            // lazy pdo
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            // parameters array
            ->parametersArray(TestObject4::class, [
                'bar' => 'bar-value',
                'baz' => 'baz-value',
                'arr' => ['a', 'b', 'c']
            ]);

        $di = new Container($definitions);
        $inst = $di->get(TestObject4::class);
        $this->assertInstanceOf(TestObject4::class, $inst);
        $this->assertSame('bar-value', $inst->bar);
        $this->assertSame('baz-value', $inst->baz);
        $this->assertSame(['a', 'b', 'c'], $inst->arr);
    }

    /**
     * Tricky situations may require a resolver
     *
     * Most types are looked by their class/interface as ids in the container
     * For more advanced scenarios, this doesn't work (eg: a class taking two objects of the same class)
     *
     * If you cannot set parameters directly, resolvers allow to select how a given type is mapped in the container
     */
    public function testBuildWithResolvers(): void
    {
        // Default case //
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDb', fn(): \PDO => new PDO('sqlite::memory:'));

        $di = new Container($definitions);
        $inst = $di->get(TestObjectTwoPdosVal::class);
        $pdo = $di->get(PDO::class);
        $backupDb = $di->get('backupDb');
        $this->assertInstanceOf(TestObjectTwoPdosVal::class, $inst);
        $this->assertInstanceOf(PDO::class, $inst->db);
        $this->assertInstanceOf(PDO::class, $inst->backupDb);
        $this->assertNotSame($pdo, $backupDb);
        $this->assertSame($pdo, $inst->db);
        // Without resolvers, the backupDb service is not being used because it's resolved by type
        $this->assertNotSame($backupDb, $inst->backupDb);

        // Resolved case //
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDb', fn(): \PDO => new PDO('sqlite::memory:'))
            // this won't work, parameters cannot reference services
            // ->parameter(TestObjectTwoPdosVal::class, 'backupDb', 'backupDb')
            // this means : when resolving pdo classes, if the name is backupDb, using backupDb id
            ->resolve(PDO::class, 'backupDb', 'backupDb')
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

        // Resolved case using closure //
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDbService', fn(): \PDO => new PDO('sqlite::memory:'))
            // this means : when resolving pdo classes, for any name, resolve using the closure
            ->resolve(PDO::class, '*', function (string $name, string $class) {
                // Using the closure, we can check the argument name and the class being resolved
                if ($class === TestObjectTwoPdosVal::class && $name === 'backupDb') {
                    return 'backupDbService'; // use custom service
                }
                return PDO::class; // default
            })
            ->lock();

        $di = new Container($definitions);
        $inst = $di->get(TestObjectTwoPdosVal::class);
        $pdo = $di->get(PDO::class);
        $backupDb = $di->get('backupDbService');
        $this->assertInstanceOf(TestObjectTwoPdosVal::class, $inst);
        $this->assertInstanceOf(PDO::class, $inst->db);
        $this->assertInstanceOf(PDO::class, $inst->backupDb);
        $this->assertNotSame($pdo, $backupDb);
        $this->assertSame($pdo, $inst->db);
        $this->assertSame($backupDb, $inst->backupDb);

        // Resolved case using closure and resolveAll //
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDbService', fn(): \PDO => new PDO('sqlite::memory:'))
            // this means : when resolving pdo classes, for any name, resolve using the closure
            ->resolveAll(PDO::class, function (string $name, string $class) {
                // Using the closure, we can check the argument name and the class being resolved
                if ($class === TestObjectTwoPdosVal::class && $name === 'backupDb') {
                    return 'backupDbService'; // use custom service
                }
                return PDO::class; // default
            })
            ->lock();

        $di = new Container($definitions);
        $inst = $di->get(TestObjectTwoPdosVal::class);
        $pdo = $di->get(PDO::class);
        $backupDb = $di->get('backupDbService');
        $this->assertInstanceOf(TestObjectTwoPdosVal::class, $inst);
        $this->assertInstanceOf(PDO::class, $inst->db);
        $this->assertInstanceOf(PDO::class, $inst->backupDb);
        $this->assertNotSame($pdo, $backupDb);
        $this->assertSame($pdo, $inst->db);
        $this->assertSame($backupDb, $inst->backupDb);

        // Resolved by class //
        $definitions = Definitions::create()
            ->set(PDO::class, fn(): \PDO => new PDO('sqlite::memory:'))
            ->set('backupDb', fn(): \PDO => new PDO('sqlite::memory:'))
            // this means : when resolving pdo classes, if the interface is TestObjectTwoPdosInterface, resolve using the closure
            // this can be used to provide a given argument to all instance of a given type regardless of the actual parameter name
            ->resolve(PDO::class, TestObjectTwoPdosInterface::class, function (string $name, string $class) {
                // Check the class is always TestObjectTwoPdosVal
                assert($class === TestObjectTwoPdosVal::class);
                // Using the closure, we can check the argument name
                if ($name === 'backupDb') {
                    return 'backupDb'; // use custom service
                }
                return PDO::class; // default
            })
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

        // Resolver doesn't affect other classes
        $otherInst = $di->get(TestObjectSinglePdoVal::class);
        $this->assertSame($pdo, $otherInst->backupDb); // it's the default service
    }

    /**
     * You can run extra code after object instantiation
     * This only happens once
     */
    public function testBuildWithCallbacks(): void
    {
        // Calling testMethod
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
        // Objects are cached after callbacks are called.
        $i = 0;
        $pdo = new PDO('sqlite::memory:');
        $definitions = Definitions::create()
            ->set(PDO::class, $pdo)
            ->callback(TestObject2::class, function (TestObject2 $obj) use ($i): void {
                $i++;
                $obj->v = 'value is ' . $i;
            });

        $di = new Container($definitions);
        $inst = $di->get(TestObject2::class);
        $inst2 = $di->get(TestObject2::class);
        $this->assertSame('value is 1', $inst->v);
        $this->assertSame('value is 1', $inst2->v);

        TestObject2::$counter = 0;
    }

    /**
     * When using callbacks, this can also trigger interface callbacks
     * This way, you can define callbacks by class AND by interface
     * The order matters: interfaces callbacks are executed first and named callbacks are executed last
     */
    public function testBuildWithInterfaceCallbacks(): void
    {
        $definitions = Definitions::create()
            ->bind(TestObject::class)
            ->callback(TestInterface::class, function (TestInterface $obj): void {
                $obj->setVal('bar'); // this gets overwritten by the named callback
            })
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('baz');
            });

        $di = new Container($definitions);
        // We retrieving an object directly, it's interface callbacks are not called
        $inst = $di->get(TestObject::class);
        $this->assertSame('baz', $inst->getVal());
        // When retrieving an object through its interface, callbacks are called
        $inst = $di->get(TestInterface::class);
        $this->assertSame('baz', $inst->getVal());
    }

    /**
     * When using callbacks, you can define specific callbacks for a given base class
     * This way, you can define callbacks by base class AND by subclasses
     * The order matters: base class callbacks are executed first and subclass callbacks are executed last
     */
    public function testBuildWithSubclassesCallbacks(): void
    {
        $definitions = Definitions::create()
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('baz');
            });

        $di = new Container($definitions);

        // Base object has a value
        $inst = $di->get(TestObject::class);
        $this->assertSame('baz', $inst->getVal());
        // Subclass also gets the value, even if it has no dedicated callbacks
        $inst = $di->get(TestObjectSub::class);
        $this->assertEmpty($definitions->callbacksFor(TestObjectSub::class));
        $this->assertNotEmpty($definitions->callbacksFor(TestObject::class));
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
        // We retrieving an object directly, we get its callbacks
        $inst = $di->get(TestObject::class);
        $this->assertSame('baz', $inst->getVal());
        // When retrieving a subclass, parent's callbacks are overwritten because they are executed first
        $inst = $di->get(TestObjectSub::class);
        $this->assertSame('bar', $inst->getVal());
    }

    /**
     * When using callbacks, you can define specific callbacks for a given service
     * This way, you can define callbacks by base class AND by service
     * The order matters: base class callbacks are executed first and service callbacks are executed last
     */
    public function testBuildWithServicesCallbacks(): void
    {
        $definitions = Definitions::create()
            ->set('custom', TestObject::class)
            ->callback('custom', function (TestObject $obj): void {
                $this->assertEquals('bar', $obj->getVal()); // it is set by base class
                $obj->setVal('baz');
            })
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('bar'); // this is overwritten by service
            });

        $di = new Container($definitions);
        $inst = $di->get(TestObject::class);
        $this->assertSame('bar', $inst->getVal());
        // When retrieving a service, base class callbacks are called first
        $inst = $di->get('custom');
        $this->assertSame('baz', $inst->getVal());
    }

    /**
     * When naming a callback, we can override it later if needed
     */
    public function testNamedCallback(): void
    {
        $definitions = Definitions::create()
            ->callback(TestObject::class, function (TestObject $obj): void {
                $obj->setVal('bar'); // this is overwritten later
            }, 'my_callback');

        // later in another galaxy far far away
        $definitions->callback(TestObject::class, function (TestObject $obj): void {
            $obj->setVal('baz');
        }, 'my_callback');

        $di = new Container($definitions);
        $inst = $di->get(TestObject::class);
        $this->assertCount(1, $definitions->callbacksFor(TestObject::class));
        $this->assertSame('baz', $inst->getVal());
    }

    /**
     * Cloning allows to get a "fresh" container with no cache
     */
    public function testClone(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $definitions = Definitions::create()
            ->set(PDO::class, $pdo)
            ->parameter(TestObject4::class, 'bar', 'bar-value')
            ->parameter(TestObject4::class, 'baz', 'baz-value');

        $di = new Container($definitions);
        $inst = $di->get(TestObject4::class);
        $di2 = clone $di;
        $inst2 = $di2->get(TestObject4::class);

        $this->assertNotSame($inst, $inst2);
    }
}
