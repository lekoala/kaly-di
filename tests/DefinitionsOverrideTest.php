<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Closure;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestAlternativeObject;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Contract for additive composition and intentional replacement.
 *
 * Service definitions are additive by default: a duplicate id fails fast.
 * rebind() is the only operation that deliberately replaces a definition.
 */
class DefinitionsOverrideTest extends TestCase
{
    public function testSetRejectsExistingDefinition(): void
    {
        $def = Definitions::create()->set('service', TestObject::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rebind');
        $def->set('service', TestObject2::class);
    }

    public function testBindRejectsExistingDefinition(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rebind');
        $def->bind(TestInterface::class, TestAlternativeObject::class);
    }

    public function testSetAndBindCannotSilentlyReplaceEachOther(): void
    {
        try {
            Definitions::create()->set(TestInterface::class, TestObject::class)->bind(
                TestInterface::class,
                TestAlternativeObject::class,
            );
            $this->fail('bind() must reject an id already registered with set()');
        } catch (LogicException $e) {
            $this->assertStringContainsString('rebind', $e->getMessage());
        }

        try {
            Definitions::create()->bind(TestInterface::class, TestObject::class)->set(
                TestInterface::class,
                TestAlternativeObject::class,
            );
            $this->fail('set() must reject an id already registered with bind()');
        } catch (LogicException $e) {
            $this->assertStringContainsString('rebind', $e->getMessage());
        }
    }

    public function testRebindReplacesExistingDefinition(): void
    {
        $def = Definitions::create()
            ->bind(TestInterface::class, TestObject::class)
            ->rebind(TestInterface::class, TestAlternativeObject::class);

        $this->assertSame(TestAlternativeObject::class, $def->get(TestInterface::class));
    }

    public function testRebindCanReplaceASetDefinition(): void
    {
        $definitions = Definitions::create()->set('mailer', TestObject::class)->rebind(
            'mailer',
            TestAlternativeObject::class,
        );

        $this->assertSame(TestAlternativeObject::class, $definitions->get('mailer'));
    }

    public function testRebindRequiresExistingDefinition(): void
    {
        $def = Definitions::create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no existing definition');
        $def->rebind('mailer', TestObject::class);
    }

    public function testRebindRejectsIncompatibleClassStringWhenIdIsAnInterface(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $this->expectException(AssertionError::class);
        $def->rebind(TestInterface::class, TestObject2::class);
    }

    public function testRebindRejectsIncompatibleObjectWhenIdIsAnInterface(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $this->expectException(AssertionError::class);
        $def->rebind(TestInterface::class, new TestObject2('v'));
    }

    public function testRebindAcceptsAFreeClosureForATypedId(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);
        $def->rebind(TestInterface::class, fn(): TestAlternativeObject => new TestAlternativeObject());

        $this->assertInstanceOf(Closure::class, $def->get(TestInterface::class));
    }

    public function testMergeRejectsConflictingDefinition(): void
    {
        $base = Definitions::create()->set('mailer', TestObject::class);
        $incoming = Definitions::create()->set('mailer', TestObject2::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rebind');
        $base->merge($incoming);
    }

    public function testMergeRejectsEvenIdenticalDuplicateDefinition(): void
    {
        $base = Definitions::create()->set('mailer', TestObject::class);
        $incoming = Definitions::create()->set('mailer', TestObject::class);

        $this->expectException(LogicException::class);
        $base->merge($incoming);
    }

    public function testFailedMergeLeavesDefinitionsUnchanged(): void
    {
        $base = Definitions::create()->set('existing', TestObject::class);
        $base->parameter('existing', 'p', 1);
        $base->callback('existing', fn() => null);

        $incoming = Definitions::create()
            ->set('new-service', TestObject2::class)
            ->set('existing', TestAlternativeObject::class)
            ->parameter('existing', 'p2', 2)
            ->callback('existing', fn() => null);

        try {
            $base->merge($incoming);
            $this->fail('A conflicting merge must throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('rebind', $e->getMessage());
        }

        $this->assertFalse($base->has('new-service'));
        $this->assertSame(TestObject::class, $base->get('existing'));
        $this->assertArrayNotHasKey('p2', $base->parametersFor('existing'));
        $this->assertCount(1, $base->callbacksFor('existing'));
    }

    public function testMergeStillCombinesDisjointDefinitions(): void
    {
        $base = Definitions::create()->set('a', TestObject::class);
        $incoming = Definitions::create()->set('b', TestObject2::class);

        $base->merge($incoming);

        $this->assertTrue($base->has('a'));
        $this->assertTrue($base->has('b'));
        $this->assertSame(TestObject::class, $base->get('a'));
        $this->assertSame(TestObject2::class, $base->get('b'));
    }
}
