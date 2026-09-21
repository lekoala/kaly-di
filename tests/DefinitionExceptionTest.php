<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Container;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use stdClass;

/**
 * Unconditional configuration invariants.
 *
 * These facts are already known and cheap to check, so they must fail the same
 * way whether or not assertions are enabled. They never autoload or reflect
 * code the runtime might not visit.
 */
class DefinitionExceptionTest extends TestCase
{
    public function testItIsALogicExceptionAndAPsrContainerException(): void
    {
        $exception = new DefinitionException('nope');

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    public function testMutatingLockedDefinitionsThrows(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->lock();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('locked');
        $definitions->set('other', TestObject::class);
    }

    public function testDuplicateSetThrows(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class);

        $this->expectException(DefinitionException::class);
        $definitions->set('service', TestObject2::class);
    }

    public function testDuplicateBindThrows(): void
    {
        $definitions = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $this->expectException(DefinitionException::class);
        $definitions->bind(TestInterface::class, TestObject::class);
    }

    public function testMergeCollisionThrows(): void
    {
        $base = Definitions::create()->set('a', TestObject::class);
        $incoming = Definitions::create()->set('a', TestObject2::class);

        $this->expectException(DefinitionException::class);
        $base->merge($incoming);
    }

    public function testRebindUnknownIdThrows(): void
    {
        $definitions = Definitions::create();

        $this->expectException(DefinitionException::class);
        $definitions->rebind('mailer', TestObject::class);
    }

    public function testRebindExpectedMismatchThrows(): void
    {
        $definitions = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $this->expectException(DefinitionException::class);
        $definitions->rebind(TestInterface::class, TestObject::class, expected: TestObject2::class);
    }

    public function testStdClassIdThrows(): void
    {
        $definitions = Definitions::create();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('stdClass');
        $definitions->set(stdClass::class, TestObject::class);
    }

    public function testInvalidFactoryResultThrowsFromGet(): void
    {
        $container = Definitions::create()->set('bad', fn() => null)->createContainer();

        try {
            $container->get('bad');
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('must return an object or a class-string', $e->getMessage());
            // The configuration error is preserved, not wrapped in a ContainerException
            $this->assertNull($e->getPrevious());
        }
    }

    public function testInvalidFactoryResultIsNotWrapped(): void
    {
        $container = new Container(Definitions::create()->set('bad', fn(): int => 42));

        $this->expectException(DefinitionException::class);
        $container->get('bad');
    }
}
