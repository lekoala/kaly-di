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
use RuntimeException;
use stdClass;

/**
 * The resolution guard in Container::get() must cover the whole resolution:
 * factories, constructor and callbacks.
 */
class ResolutionGuardTest extends TestCase
{
    public function testResolutionRecoversAfterFactoryFailure(): void
    {
        $calls = 0;
        $di = Definitions::create()->set('retry', function () use (&$calls): TestObject {
            if (++$calls === 1) {
                throw new LogicException('factory failed');
            }
            return new TestObject();
        })->createContainer();

        try {
            $di->get('retry');
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
        $this->assertSame(1, $calls);

        $instance = $di->get('retry');
        $this->assertInstanceOf(TestObject::class, $instance);
        $this->assertSame(2, $calls);
        $this->assertSame($instance, $di->get('retry'));
        $this->assertSame(2, $calls);
    }

    public function testResolutionRecoversAfterCallbackFailure(): void
    {
        $calls = 0;
        $di = Definitions::create()
            ->callback(TestObject::class, function (TestObject $obj) use (&$calls): void {
                if (++$calls === 1) {
                    throw new LogicException('callback failed');
                }
            })
            ->createContainer();

        try {
            $di->get(TestObject::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
        $this->assertSame(1, $calls);

        $instance = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $instance);
        $this->assertSame(2, $calls);
        $this->assertSame($instance, $di->get(TestObject::class));
        $this->assertSame(2, $calls);
    }

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

    public function testCircularFailureLeavesNoPartialStateAndCanReplay(): void
    {
        $attemptsA = 0;
        $definitions = Definitions::create()->set('a', function (ContainerInterface $c) use (&$attemptsA): object {
            $attemptsA++;
            $c->get('b');
            return new stdClass();
        })->set('b', fn(ContainerInterface $c): object => $c->get('a'));

        $di = new Container($definitions);

        foreach ([1, 2] as $expectedAttempts) {
            try {
                $di->get('a');
                $this->fail('Expected a CircularReferenceException');
            } catch (CircularReferenceException $e) {
                $this->assertStringContainsString('Circular reference to `a`', $e->getMessage());

                // Nothing cached, marker of the failed call cleaned: the next
                // get() re-runs the whole factory chain instead of tripping
                // over a stale building marker.
            }
            $this->assertSame($expectedAttempts, $attemptsA);
        }
    }

    public function testErrorsWhileCheckingExistenceAreWrapped(): void
    {
        $di = new Container();
        $missing = 'Kaly\\Tests\\Broken\\MissingClass';

        spl_autoload_register(
            $loader = static function (string $class) use ($missing): void {
                if ($class === $missing) {
                    throw new RuntimeException('autoload boom');
                }
            },
        );

        try {
            $di->get($missing);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString("Unable to check `{$missing}`", $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        } finally {
            spl_autoload_unregister($loader);
        }

        // has() itself stays raw: the same failure surfaces unwrapped
        spl_autoload_register(
            $loader = static function (string $class) use ($missing): void {
                if ($class === $missing) {
                    throw new RuntimeException('autoload boom');
                }
            },
        );

        try {
            $di->has($missing);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('autoload boom', $e->getMessage());
        } finally {
            spl_autoload_unregister($loader);
        }
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
