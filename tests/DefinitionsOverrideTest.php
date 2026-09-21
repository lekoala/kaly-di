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

    public function testRebindWithMatchingExpectedReplacesDefinition(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        $def->rebind(TestInterface::class, TestAlternativeObject::class, expected: TestObject::class);

        $this->assertSame(TestAlternativeObject::class, $def->get(TestInterface::class));
    }

    public function testRebindWithMismatchedExpectedIsRejected(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        try {
            $def->rebind(TestInterface::class, TestAlternativeObject::class, expected: TestObject2::class);
            $this->fail('A mismatched expected precondition must be rejected');
        } catch (LogicException $e) {
            $this->assertStringContainsString('expected', $e->getMessage());
            $this->assertStringContainsString('currently defined', $e->getMessage());
        }

        $this->assertSame(TestObject::class, $def->get(TestInterface::class));
    }

    public function testRebindExpectedComparesObjectIdentityNotEquality(): void
    {
        $first = new TestAlternativeObject();
        $second = new TestAlternativeObject();
        $def = Definitions::create()->set('alternative', $first);

        $def->rebind('alternative', $second, expected: $first);
        $this->assertSame($second, $def->get('alternative'));

        // A different instance of the same class is not the expected value
        try {
            $def->rebind('alternative', $first, expected: $first);
            $this->fail('expected must compare identity, not class or equality');
        } catch (LogicException $e) {
            $this->assertStringContainsString('TestAlternativeObject#', $e->getMessage());
        }
    }

    public function testRebindExpectedComparesClosureIdentity(): void
    {
        $factory = fn(): TestAlternativeObject => new TestAlternativeObject();
        $otherFactory = fn(): TestAlternativeObject => new TestAlternativeObject();
        $def = Definitions::create()->set('service', $factory);

        $def->rebind('service', $otherFactory, expected: $factory);
        $this->assertSame($otherFactory, $def->get('service'));

        try {
            $def->rebind('service', $factory, expected: $factory);
            $this->fail('expected must compare closure identity');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Closure#', $e->getMessage());
        }
    }

    public function testRebindExpectedDetectsStaleOverride(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        // Someone else changes the composition in between
        $def->rebind(TestInterface::class, TestAlternativeObject::class);

        try {
            $def->rebind(TestInterface::class, TestObject::class, expected: TestObject::class);
            $this->fail('A stale expected precondition must be rejected');
        } catch (LogicException $e) {
            $this->assertStringContainsString('expected', $e->getMessage());
            $this->assertStringContainsString('currently defined', $e->getMessage());
        }
    }

    public function testRebindExpectedTakesPrecedenceOverInvalidValue(): void
    {
        $def = Definitions::create()->bind(TestInterface::class, TestObject::class);

        try {
            // The precondition is stale AND the new value is incompatible:
            // the stale precondition is reported first.
            $def->rebind(TestInterface::class, TestObject2::class, expected: TestAlternativeObject::class);
            $this->fail('A stale expected precondition must be rejected');
        } catch (LogicException $e) {
            $this->assertStringContainsString('expected', $e->getMessage());
        }

        $this->assertSame(TestObject::class, $def->get(TestInterface::class));
    }
}
