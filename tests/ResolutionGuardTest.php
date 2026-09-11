<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObjectSelf;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The resolution guard in Container::get() must cover the whole resolution:
 * factories, constructor and callbacks.
 */
class ResolutionGuardTest extends TestCase
{
    public function testItDetectsDirectConstructorCircularDependency(): void
    {
        $di = new Container();
        $this->expectException(CircularReferenceException::class);

        $di->get(TestObjectSelf::class);
    }

    public function testItDetectsFactoryCircularReference(): void
    {
        $di = new Container(Definitions::create()->set('a', fn(ContainerInterface $c): object => $c->get('a')));

        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessage('Circular reference to `a`');
        $di->get('a');
    }

    public function testItDetectsIndirectFactoryCircularReference(): void
    {
        $definitions = Definitions::create()->set('a', fn(ContainerInterface $c): object => $c->get(
            'b',
        ))->set('b', fn(ContainerInterface $c): object => $c->get('a'));

        $di = new Container($definitions);

        $this->expectException(CircularReferenceException::class);
        $di->get('a');
    }

    public function testItDetectsCallbackCircularReference(): void
    {
        $definitions = Definitions::create()->callback(TestObject::class, function (
            TestObject $obj,
            ContainerInterface $c,
        ): void {
            $c->get(TestObject::class);
        });

        $di = new Container($definitions);

        $this->expectException(CircularReferenceException::class);
        $di->get(TestObject::class);
    }

    public function testFactoryExceptionIsWrappedInContainerException(): void
    {
        $di = new Container(Definitions::create()->set(
            'boom',
            fn(): object => throw new LogicException('factory boom'),
        ));

        try {
            $di->get('boom');
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Unable to create object `boom`', $e->getMessage());
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }

    public function testCallbackExceptionIsWrappedInContainerException(): void
    {
        $definitions = Definitions::create()->callback(
            TestObject::class,
            fn(TestObject $obj) => throw new LogicException('callback boom'),
        );

        $di = new Container($definitions);

        try {
            $di->get(TestObject::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Unable to create object `' . TestObject::class . '`', $e->getMessage());
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }
}
