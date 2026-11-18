<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\AliasValidator;
use Kaly\Di\ContainerException;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The alias type check may autoload the alias id. An autoloader failure
     * must not escape get() as a raw exception: consumers catch PSR-11 types.
     */
    public function testAliasTypeCheckNormalizesAutoloaderErrors(): void
    {
        $container = Definitions::create()
            ->set('target', new TestObject())
            ->alias('alias', 'target')
            ->createContainer();
        $failure = new \RuntimeException('autoload failed');
        $autoloader = static function (string $class) use ($failure): void {
            if ($class === 'alias') {
                throw $failure;
            }
        };
        spl_autoload_register($autoloader);

        try {
            $this->assertTrue($container->has('alias'));
            try {
                $container->get('alias');
                $this->fail('Expected a ContainerException');
            } catch (ContainerException $e) {
                $this->assertSame($failure, $e->getPrevious());
                $this->assertStringContainsString('`alias`', $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($autoloader);
        }
    }

    /**
     * An autoloader may resolve its own dependencies through the container.
     * When the alias type check triggers it, a missing entry must surface as
     * a ContainerException, never as a NotFoundExceptionInterface: has()
     * already reported the alias as available, and PSR-11 reserves NotFound
     * for the requested id itself.
     */
    public function testAliasTypeCheckWrapsANestedNotFound(): void
    {
        $container = Definitions::create()
            ->set('target', new TestObject())
            ->alias('review_alias', 'target')
            ->createContainer();
        $autoloader = static function (string $class) use ($container): void {
            if ($class === 'review_alias') {
                $container->get('missing_dependency');
            }
        };
        spl_autoload_register($autoloader);

        try {
            $this->assertTrue($container->has('review_alias'));
            try {
                $container->get('review_alias');
                $this->fail('Expected a ContainerException');
            } catch (ContainerException $e) {
                $this->assertStringContainsString('`review_alias`', $e->getMessage());
                $this->assertInstanceOf(ReferenceNotFoundException::class, $e->getPrevious());
                $this->assertStringContainsString('`missing_dependency`', $e->getPrevious()->getMessage());
            }
        } finally {
            spl_autoload_unregister($autoloader);
        }
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
        $base = Definitions::create()->set('service', TestObject::class)->alias('existing', 'service');
        $incoming = Definitions::create()
            ->set('other', TestObject2::class)
            ->parameter('other', 'v', 'merged')
            ->alias('alias', 'other');

        $base->merge($incoming);

        $this->assertTrue($base->hasAlias('alias'));
        $this->assertSame('other', $base->getAlias('alias'));
        $this->assertTrue($base->has('other'));
        $this->assertSame(['existing' => 'service', 'alias' => 'other'], $base->getAliases());

        $container = $base->createContainer();
        $this->assertSame($container->get('service'), $container->get('existing'));
        $this->assertSame($container->get('other'), $container->get('alias'));
    }

    public function testMergeRejectsAnIncomingAliasThatShadowsAService(): void
    {
        $base = Definitions::create()->set('alias', TestObject::class);
        $incoming = Definitions::create()->set('target', TestObject2::class)->alias('alias', 'target');
        $before = clone $base;

        try {
            $base->merge($incoming);
            $this->fail('Expected an alias collision');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('alias', $e->getMessage());
        }

        $this->assertEquals($before, $base);
    }

    /** @return array<string,array{bool,bool}> */
    public static function aliasConfigurationConflicts(): array
    {
        return [
            'incoming parameters' => [false, false],
            'incoming callbacks' => [false, true],
            'incoming alias with existing parameters' => [true, false],
            'incoming alias with existing callbacks' => [true, true],
        ];
    }

    #[DataProvider('aliasConfigurationConflicts')]
    public function testMergeRejectsAliasConfigurationAtomically(bool $incomingAlias, bool $callback): void
    {
        $aliases = Definitions::create()->set('service', TestObject::class)->alias('alias', 'service');
        $configuration = Definitions::create()->set('other', TestObject2::class);
        if ($callback) {
            $configuration->callback('alias', fn() => null);
        } else {
            $configuration->parameter('alias', 'v', 'invalid');
        }
        $base = $incomingAlias ? $configuration : $aliases;
        $incoming = $incomingAlias ? $aliases : $configuration;
        $before = clone $base;

        try {
            $base->merge($incoming);
            $this->fail('Expected an alias configuration conflict');
        } catch (DefinitionException $e) {
            $this->assertSame('`alias` is an alias and cannot have parameters or callbacks.', $e->getMessage());
        }

        $this->assertEquals($before, $base);
    }

    public function testAliasValidatorRejectsACycle(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('alias cycle');

        AliasValidator::assertValid([], ['a' => 'b', 'b' => 'a'], [], []);
    }

    public function testMergeValidatesALongAliasChain(): void
    {
        $chain = Definitions::create()->set('service', TestObject::class);
        $previous = 'service';
        for ($index = 0; $index < 500; $index++) {
            $alias = 'a' . $index;
            $chain->alias($alias, $previous);
            $previous = $alias;
        }

        $base = Definitions::create();
        $base->merge($chain);

        $container = $base->createContainer();

        $this->assertSame($container->get('service'), $container->get('a499'));
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
