<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateTimeImmutable;
use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Di\Reflection;
use Kaly\Tests\Mocks\TestChild;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestOptionalClock;
use Kaly\Tests\Mocks\TestParent;
use Kaly\Tests\Mocks\TestSelfTyped;
use Kaly\Tests\Mocks\TestSubContainer;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use stdClass;

/**
 * Entry identity (canonical class ids), the PSR-11 not-found contract for
 * nested lookups, and type consistency of produced entries.
 */
class IdentityAndTypingTest extends TestCase
{
    /**
     * PSR-11: NotFoundExceptionInterface means "no entry for this id". A
     * missing entry looked up by a factory is a failure of the outer entry.
     */
    public function testNestedNotFoundIsNotReportedAsNotFound(): void
    {
        $di = new Container(Definitions::create()->set('a', fn(Container $c) => $c->get('missing')));
        $this->assertTrue($di->has('a'));

        try {
            $di->get('a');
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
            $this->assertInstanceOf(ReferenceNotFoundException::class, $e->getPrevious());
            $this->assertStringContainsString('`missing` is not set', $e->getMessage());
        }
    }

    public function testClassIdsShareTheCanonicalInstance(): void
    {
        $di = new Container();
        $inst = $di->get(TestObject::class);

        $this->assertTrue($di->has('\\' . TestObject::class));
        $this->assertSame($inst, $di->get('\\' . TestObject::class));
        $this->assertSame($inst, $di->get(strtolower(TestObject::class)));
    }

    public function testNonCanonicalClassIdUsesTheDefinition(): void
    {
        $calls = 0;
        $definition = new TestObject();
        $di = new Container(Definitions::create()->set(
            TestInterface::class,
            $definition,
        )->callback(TestInterface::class, function () use (&$calls): void {
            $calls++;
        }));

        $this->assertTrue($di->has('\\' . TestInterface::class));
        $this->assertSame($definition, $di->get('\\' . TestInterface::class));
        $this->assertSame($definition, $di->get(TestInterface::class));
        $this->assertSame(1, $calls);
    }

    public function testCustomIdsAreNotNormalized(): void
    {
        $di = new Container(Definitions::create()->set('app.object', TestObject::class));

        $this->assertFalse($di->has('App.Object'));
        $this->assertFalse($di->has('\\app.object'));
    }

    public function testInternalClassesAreNotAutowired(): void
    {
        $di = new Container();

        $this->assertFalse($di->has(DateTimeImmutable::class));
        $this->assertFalse($di->has(PDO::class));
    }

    public function testOptionalInternalDependencyFallsBackToItsDefault(): void
    {
        $di = new Container();
        $this->assertNull($di->get(TestOptionalClock::class)->now);

        $now = new DateTimeImmutable();
        $di = new Container(Definitions::create()->set(DateTimeImmutable::class, $now));
        $this->assertSame($now, $di->get(TestOptionalClock::class)->now);
    }

    public function testContainerSubclassesAreNotAutowired(): void
    {
        $this->assertFalse((new Container())->has(TestSubContainer::class));
    }

    public function testInterfaceDefinitionMustProduceAnImplementation(): void
    {
        $di = new Container(Definitions::create()->set(TestInterface::class, fn() => new stdClass()));

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('does not implement');
        $di->get(TestInterface::class);
    }

    public function testInterfaceDefinitionObjectMustBeAnImplementation(): void
    {
        $di = new Container(Definitions::create()->set(TestInterface::class, new stdClass()));

        $this->expectException(DefinitionException::class);
        $di->get(TestInterface::class);
    }

    public function testSelfAndParentTypesAreResolved(): void
    {
        $same = (new ReflectionMethod(TestSelfTyped::class, 'same'))->getParameters()[0];
        $up = (new ReflectionMethod(TestSelfTyped::class, 'up'))->getParameters()[0];

        $this->assertSame(TestSelfTyped::class, Reflection::getParameterTypeName($same));
        $this->assertSame(TestParent::class, Reflection::getParameterTypeName($up));
        $this->assertSame(TestParent::class, Reflection::getParameterClass($up)?->getName());

        $injector = new Injector(new Container());
        $node = new TestSelfTyped();
        $this->assertSame($node, $injector->invoke([TestSelfTyped::class, 'same'], $node));
        $child = new TestChild();
        $this->assertSame($child, $injector->invoke([TestSelfTyped::class, 'up'], $child));
    }

    public function testAnonymousCallbackKeysDoNotCollideWithExplicitNames(): void
    {
        $anonymous = function (): void {};
        $definitions = Definitions::create()
            ->callback(TestObject::class, $anonymous)
            ->callback(TestObject::class, function (): void {}, (string) spl_object_id($anonymous));

        $this->assertCount(2, $definitions->callbacksFor(TestObject::class));
    }

    public function testBindRejectsStdClassAsId(): void
    {
        $this->expectException(DefinitionException::class);
        Definitions::create()->bind(stdClass::class, TestObject::class);
    }
}
