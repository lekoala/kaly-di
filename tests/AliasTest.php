<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use PHPUnit\Framework\TestCase;

/**
 * Aliases delegate resolution to a target: same shared instance, configured
 * once. They cannot carry their own parameters or callbacks.
 */
class AliasTest extends TestCase
{
    public function testAliasReturnsTheSameSharedInstance(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');
        $container = $definitions->createContainer();

        $this->assertTrue($container->has('alias'));
        $this->assertSame($container->get('service'), $container->get('alias'));
    }

    public function testAliasConfiguresTheTargetOnlyOnce(): void
    {
        $calls = 0;
        $definitions = Definitions::create()
            ->set('service', TestObject::class)
            ->callback(TestObject::class, function () use (&$calls): void {
                $calls++;
            })
            ->alias('alias', 'service');
        $container = $definitions->createContainer();

        $container->get('alias');
        $container->get('service');
        $container->get('alias');

        $this->assertSame(1, $calls);
    }

    public function testAliasCanTargetAnotherAlias(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('a', 'service')->alias('b', 'a');
        $container = $definitions->createContainer();

        $this->assertSame($container->get('service'), $container->get('b'));
    }

    public function testAliasRequiresAnExistingTarget(): void
    {
        $definitions = Definitions::create();

        $this->expectException(DefinitionException::class);
        $definitions->alias('alias', 'missing');
    }

    public function testAliasCannotBeRedefined(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');

        $this->expectException(DefinitionException::class);
        $definitions->alias('alias', 'service');
    }

    public function testSetRejectsAnAliasId(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');

        $this->expectException(DefinitionException::class);
        $definitions->set('alias', TestObject2::class);
    }

    public function testAliasRejectsExistingParameters(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class);
        $definitions->parameter('alias', 'x', 1);

        $this->expectException(DefinitionException::class);
        $definitions->alias('alias', 'service');
    }

    public function testParameterOnAnAliasIsRejected(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');

        $this->expectException(DefinitionException::class);
        $definitions->parameter('alias', 'x', 1);
    }

    public function testCallbackOnAnAliasIsRejected(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');

        $this->expectException(DefinitionException::class);
        $definitions->callback('alias', fn() => null);
    }

    public function testAliasIsNotRebindable(): void
    {
        $definitions = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');

        $this->expectException(DefinitionException::class);
        $definitions->rebind('alias', TestObject2::class);
    }

    public function testMergeRejectsAnAliasCollision(): void
    {
        $base = Definitions::create()->set('service', TestObject::class)->alias('x', 'service');
        $incoming = Definitions::create()->set('x', TestObject2::class);

        $this->expectException(DefinitionException::class);
        $base->merge($incoming);
    }

    public function testMergeCarriesAliases(): void
    {
        $base = Definitions::create()->set('service', TestObject::class);
        $incoming = Definitions::create()->set('other', TestObject2::class)->alias('alias', 'other');

        $base->merge($incoming);

        $this->assertTrue($base->hasAlias('alias'));
        $this->assertSame('other', $base->getAlias('alias'));
        $this->assertTrue($base->has('other'));
    }

    public function testMergeCarriesANumericAlias(): void
    {
        $base = Definitions::create()->set('service', TestObject::class);
        $incoming = Definitions::create()->set('target', TestObject2::class)->alias('123', 'target');

        $base->merge($incoming);

        $this->assertTrue($base->hasAlias('123'));
        $this->assertSame('target', $base->getAlias('123'));
    }
}
