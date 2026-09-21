<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use PHPUnit\Framework\TestCase;

/**
 * Optional per-id provenance, used only for diagnostics. It must never require
 * autoloading or reflection.
 */
class ProvenanceTest extends TestCase
{
    public function testSourceIsStoredOnSetAndBind(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class, 'billing')->bind(
            \Kaly\Tests\Mocks\TestInterface::class,
            TestObject::class,
            'catalog',
        );

        $this->assertSame(['initial' => 'billing', 'last' => 'billing'], $definitions->sourceFor('service'));
        $this->assertSame(
            ['initial' => 'catalog', 'last' => 'catalog'],
            $definitions->sourceFor(\Kaly\Tests\Mocks\TestInterface::class),
        );
        $this->assertSame([], $definitions->sourceFor('unknown'));
    }

    public function testRebindPreservesInitialSourceAndUpdatesLast(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class, 'billing');
        $definitions->rebind('service', TestObject2::class, source: 'demo');

        $this->assertSame(['initial' => 'billing', 'last' => 'demo'], $definitions->sourceFor('service'));
    }

    public function testDuplicateMessageIncludesSource(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class, 'billing');

        try {
            $definitions->set('service', TestObject2::class, 'catalog');
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('declared by `billing`', $e->getMessage());
        }
    }

    public function testMergeCollisionMessageIncludesBothSources(): void
    {
        $base = Definitions::create()->set('a', TestObject::class, 'billing');
        $incoming = Definitions::create()->set('a', TestObject2::class, 'catalog');

        try {
            $base->merge($incoming);
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('declared by `billing`', $e->getMessage());
            $this->assertStringContainsString('incoming from `catalog`', $e->getMessage());
        }
    }

    public function testMergeCarriesSources(): void
    {
        $base = Definitions::create()->set('a', TestObject::class, 'billing');
        $incoming = Definitions::create()->set('b', TestObject2::class, 'catalog');

        $base->merge($incoming);

        $this->assertSame(['initial' => 'catalog', 'last' => 'catalog'], $base->sourceFor('b'));
    }
}
