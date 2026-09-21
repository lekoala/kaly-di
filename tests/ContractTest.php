<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestNestedLeaf;
use Kaly\Tests\Mocks\TestNestedMiddle;
use Kaly\Tests\Mocks\TestNestedRoot;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObjectA;
use Kaly\Tests\Mocks\TestObjectB;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Executable specification of the public resolution contract.
 *
 * These are user-visible invariants: they must keep holding regardless of any
 * internal refactoring. If one of them has to change, it is an intentional
 * behavioral change, not an implementation detail.
 */
class ContractTest extends TestCase
{
    public function testGetReturnsTheSameSharedInstance(): void
    {
        $di = new Container();

        $first = $di->get(TestObject::class);
        $second = $di->get(TestObject::class);

        $this->assertSame($first, $second);
    }

    public function testMakeReturnsFreshInstancesAndDoesNotPopulateTheCache(): void
    {
        $container = new Container();
        $injector = new Injector($container);

        $first = $injector->make(TestObject::class);
        $second = $injector->make(TestObject::class);

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first, $container->get(TestObject::class));
    }

    public function testMissingScalarThrows(): void
    {
        $di = new Container();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessage('apiKey');
        $di->get(TestNestedLeaf::class);
    }

    public function testMissingInterfaceIsNotAvailableAndGetThrowsNotFound(): void
    {
        $di = new Container();

        $this->assertFalse($di->has(TestInterface::class));
        $this->expectException(ReferenceNotFoundException::class);
        $di->get(TestInterface::class);
    }

    public function testBoundInterfaceIsResolvable(): void
    {
        $di = new Container(Definitions::create()->bind(TestInterface::class, TestObject::class));

        $instance = $di->get(TestInterface::class);

        $this->assertInstanceOf(TestInterface::class, $instance);
        $this->assertInstanceOf(TestObject::class, $instance);
    }

    /**
     * bind() means "build this concrete class for this id", not "alias that entry".
     */
    public function testBindIsNotAnAlias(): void
    {
        $di = new Container(Definitions::create()->bind(TestInterface::class, TestObject::class));

        $byId = $di->get(TestInterface::class);
        $byClass = $di->get(TestObject::class);

        $this->assertInstanceOf(TestObject::class, $byId);
        $this->assertInstanceOf(TestObject::class, $byClass);
        $this->assertNotSame($byId, $byClass);
    }

    public function testAnAliasCanBeBuiltExplicitlyWithASetClosure(): void
    {
        $di =
            new Container(Definitions::create()->set(TestInterface::class, fn(ContainerInterface $c): object => $c->get(TestObject::class)));

        $this->assertSame($di->get(TestInterface::class), $di->get(TestObject::class));
    }

    public function testANewContainerHasAFreshServiceCache(): void
    {
        $definitions = new Definitions();
        $first = new Container($definitions);
        $second = new Container($definitions);

        // Shared within a container...
        $this->assertSame($first->get(TestObject::class), $first->get(TestObject::class));
        // ...but rebuilt for another container.
        $this->assertNotSame($first->get(TestObject::class), $second->get(TestObject::class));
    }

    public function testAnObjectInDefinitionsIsReusedAcrossContainers(): void
    {
        $object = new TestObject();
        $definitions = Definitions::create()->set(TestObject::class, $object);

        $first = new Container($definitions);
        $second = new Container($definitions);

        $this->assertSame($object, $first->get(TestObject::class));
        $this->assertSame($object, $second->get(TestObject::class));
    }

    public function testAFactoryInDefinitionsIsFreshPerContainer(): void
    {
        $definitions = Definitions::create()->set(TestObject::class, fn(): TestObject => new TestObject());

        $first = new Container($definitions);
        $second = new Container($definitions);

        $this->assertSame($first->get(TestObject::class), $first->get(TestObject::class));
        $this->assertNotSame($first->get(TestObject::class), $second->get(TestObject::class));
    }

    public function testNestedUnresolvableParameterPreservesTheCause(): void
    {
        $di = new Container();

        try {
            $di->get(TestNestedMiddle::class);
            $this->fail('Expected an UnresolvableParameterException');
        } catch (UnresolvableParameterException $e) {
            $this->assertSame('apiKey', $e->getParameterName());
            $this->assertStringContainsString(TestNestedMiddle::class, $e->getMessage());
            $this->assertStringContainsString('apiKey', $e->getMessage());

            $previous = $e->getPrevious();
            if (!$previous instanceof UnresolvableParameterException) {
                $this->fail('Expected the previous exception to be an UnresolvableParameterException');
            }
            $this->assertSame('apiKey', $previous->getParameterName());
            $this->assertStringContainsString(TestNestedLeaf::class, $previous->getMessage());
        }
    }

    public function testDeeplyNestedUnresolvableParameterPreservesEveryCause(): void
    {
        $di = new Container();

        try {
            $di->get(TestNestedRoot::class);
            $this->fail('Expected an UnresolvableParameterException');
        } catch (UnresolvableParameterException $e) {
            $chain = $this->exceptionChain($e);

            $this->assertCount(4, $chain);
            $this->assertSame('apiKey', $e->getParameterName());
            $this->assertStringContainsString(TestNestedRoot::class, $chain[0]->getMessage());
            $this->assertStringContainsString(TestNestedMiddle::class, $chain[1]->getMessage());
            $this->assertStringContainsString(TestNestedLeaf::class, $chain[2]->getMessage());
            $this->assertStringContainsString('Cannot resolve required parameter', $chain[3]->getMessage());
        }
    }

    public function testNestedCircularReferenceReportsTheChain(): void
    {
        $di = new Container();

        try {
            $di->get(TestObjectA::class);
            $this->fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException $e) {
            $this->assertStringContainsString(TestObjectA::class, $e->getMessage());
            $this->assertStringContainsString(TestObjectB::class, $e->getMessage());
        }
    }

    public function testFactoryExceptionInsideADependencyIsWrappedWithTheCause(): void
    {
        $di = new Container(Definitions::create()->set(
            TestNestedMiddle::class,
            fn(): object => throw new LogicException('factory boom'),
        ));

        try {
            $di->get(TestNestedRoot::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString(TestNestedMiddle::class, $e->getMessage());
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }

    /**
     * @return list<\Throwable>
     */
    private function exceptionChain(\Throwable $e): array
    {
        $chain = [];
        while ($e !== null) {
            $chain[] = $e;
            $e = $e->getPrevious();
        }

        return $chain;
    }
}
