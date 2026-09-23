<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Exception;
use Fiber;
use Kaly\Di\CircularReferenceException;
use Kaly\Di\ConcurrentResolutionException;
use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The building guard remembers the owning context (current Fiber, or null for
 * the main context). The same context asking again is a cycle; another
 * context asking means the owner's resolution suspended and throws a
 * ConcurrentResolutionException instead.
 */
class ConcurrentResolutionTest extends TestCase
{
    public function testExceptionIsPsr11Compatible(): void
    {
        $e = new ConcurrentResolutionException('conflict');

        $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
        $this->assertInstanceOf(Exception::class, $e);
        $this->assertSame('conflict', $e->getMessage());
    }

    public function testTwoFibersShareTheBuiltAndConfiguredService(): void
    {
        $builds = 0;
        $configs = 0;
        $container = Definitions::create()
            ->set('svc', function () use (&$builds): object {
                $builds++;
                return new TestObject();
            })
            ->callback('svc', function () use (&$configs): void {
                $configs++;
            })
            ->createContainer();

        $fromA = null;
        $fromB = null;
        $fiberA = new Fiber(function () use ($container, &$fromA): void {
            $fromA = $container->get('svc');
        });
        $fiberB = new Fiber(function () use ($container, &$fromB): void {
            $fromB = $container->get('svc');
        });
        $fiberA->start();
        $fiberB->start();

        $this->assertSame($fromA, $fromB);
        $this->assertSame(1, $builds);
        $this->assertSame(1, $configs);
    }

    public function testCycleWithinASingleFiberStaysACircularReference(): void
    {
        $container = new Container(Definitions::create()->set('a', fn(ContainerInterface $c): object => $c->get('a')));

        $fiber = new Fiber(static fn(): object => $container->get('a'));

        try {
            $fiber->start();
            $this->fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException $e) {
            $this->assertSame('Circular reference to `a` in `a`', $e->getMessage());
        }
    }

    public function testCircularChainOnlyListsTheRequestingContext(): void
    {
        $definitions = Definitions::create()->set('suspending', function (): object {
            Fiber::suspend();
            return new TestObject();
        })->set('outer', function (ContainerInterface $c): object {
            $suspended = new Fiber(static fn(): object => $c->get('suspending'));
            $suspended->start();
            // Same-context re-entry while another context owns 'suspending':
            // the chain must not mix in the independent resolution.
            return $c->get('outer');
        });
        $container = new Container($definitions);

        try {
            $container->get('outer');
            $this->fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException $e) {
            $this->assertSame('Circular reference to `outer` in `outer`', $e->getMessage());
        }
    }

    public function testSuspendedFactoryThrowsConcurrentFromMainContextAndOwnerCompletes(): void
    {
        $builds = 0;
        $container = Definitions::create()->set('svc', function () use (&$builds): object {
            $builds++;
            Fiber::suspend();
            return new TestObject();
        })->createContainer();

        $owner = new Fiber(static fn(): object => $container->get('svc'));
        $owner->start();

        try {
            $container->get('svc');
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString('another execution context', $e->getMessage());
            $this->assertStringContainsString('must not suspend', $e->getMessage());
        }

        // The refused call never cleared the owner's marker: it can finish.
        $owner->resume();

        $this->assertSame(1, $builds);
        $this->assertSame($owner->getReturn(), $container->get('svc'));
    }

    public function testSuspendedFactoryThrowsConcurrentFromAnotherFiberAndOwnerCompletes(): void
    {
        $builds = 0;
        $container = Definitions::create()->set('svc', function () use (&$builds): object {
            $builds++;
            Fiber::suspend();
            return new TestObject();
        })->createContainer();

        $owner = new Fiber(static fn(): object => $container->get('svc'));
        $owner->start();

        $competitor = new Fiber(static fn(): object => $container->get('svc'));
        try {
            $competitor->start();
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString('`svc`', $e->getMessage());
        }
        $this->assertTrue($competitor->isTerminated());

        $owner->resume();

        $this->assertSame(1, $builds);
        $this->assertSame($owner->getReturn(), $container->get('svc'));
    }

    public function testSuspendedServiceRequestedThroughAnAliasThrowsConcurrent(): void
    {
        $builds = 0;
        $container = Definitions::create()
            ->set('target', function () use (&$builds): object {
                $builds++;
                Fiber::suspend();
                return new TestObject();
            })
            ->alias('alias-id', 'target')
            ->createContainer();

        $owner = new Fiber(static fn(): object => $container->get('target'));
        $owner->start();

        try {
            $container->get('alias-id');
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString('`target`', $e->getMessage());
        }

        $owner->resume();

        $this->assertSame(1, $builds);
        $this->assertSame($container->get('target'), $container->get('alias-id'));
    }

    public function testSuspendedConstructorThrowsConcurrentAndOwnerCompletes(): void
    {
        FiberSuspendingService::$builds = 0;
        $container = new Container();

        $owner = new Fiber(static fn(): object => $container->get(FiberSuspendingService::class));
        $owner->start();

        try {
            $container->get(FiberSuspendingService::class);
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString(FiberSuspendingService::class, $e->getMessage());
        }

        $owner->resume();

        $this->assertSame(1, FiberSuspendingService::$builds);
        $this->assertSame($owner->getReturn(), $container->get(FiberSuspendingService::class));
    }

    public function testSuspendedParameterClosureThrowsConcurrentAndOwnerCompletes(): void
    {
        $container = Definitions::create()
            ->parameter(FiberParamService::class, 'locale', static function (): string {
                Fiber::suspend();
                return 'en';
            })
            ->createContainer();

        $owner = new Fiber(static fn(): object => $container->get(FiberParamService::class));
        $owner->start();

        try {
            $container->get(FiberParamService::class);
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString(FiberParamService::class, $e->getMessage());
        }

        $owner->resume();

        $instance = $container->get(FiberParamService::class);
        $this->assertSame('en', $instance->locale);
        $this->assertSame($owner->getReturn(), $instance);
    }

    public function testSuspendedCallbackThrowsConcurrentAndOwnerCompletes(): void
    {
        $configs = 0;
        $container = Definitions::create()
            ->callback(TestObject::class, function () use (&$configs): void {
                $configs++;
                Fiber::suspend();
            })
            ->createContainer();

        $owner = new Fiber(static fn(): object => $container->get(TestObject::class));
        $owner->start();

        try {
            $container->get(TestObject::class);
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString(TestObject::class, $e->getMessage());
        }

        $owner->resume();

        $this->assertSame(1, $configs);
        $this->assertSame($owner->getReturn(), $container->get(TestObject::class));
    }

    public function testConcurrentFromConstructorBodyStaysIdentifiable(): void
    {
        $container = Definitions::create()->set('suspending', function (): object {
            Fiber::suspend();
            return new TestObject();
        })->createContainer();

        $owner = new Fiber(static fn(): object => $container->get('suspending'));
        $owner->start();

        // The probe's constructor calls get('suspending') from its body: the
        // diagnostic must travel through reflection unwrapped.
        try {
            $container->get(FiberNestedProbe::class);
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString('`suspending`', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $owner->resume();
        $this->assertInstanceOf(TestObject::class, $container->get('suspending'));
    }

    public function testFailedOwnerCanBeRetriedAfterAConcurrentRefusal(): void
    {
        $attempts = 0;
        $container = Definitions::create()->set('flaky', function () use (&$attempts): object {
            $attempts++;
            if ($attempts === 1) {
                Fiber::suspend();
                throw new RuntimeException('first attempt fails');
            }
            return new TestObject();
        })->createContainer();

        $owner = new Fiber(static fn(): object => $container->get('flaky'));
        $owner->start();

        try {
            $container->get('flaky');
            $this->fail('Expected a ConcurrentResolutionException');
        } catch (ConcurrentResolutionException $e) {
            $this->assertStringContainsString('`flaky`', $e->getMessage());
        }

        // The owner fails on resume: nothing is cached for the failed service.
        try {
            $owner->resume();
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        $instance = $container->get('flaky');
        $this->assertInstanceOf(TestObject::class, $instance);
        $this->assertSame(2, $attempts);
        $this->assertSame($instance, $container->get('flaky'));
    }
}

final class FiberSuspendingService
{
    public static int $builds = 0;

    public function __construct()
    {
        self::$builds++;
        Fiber::suspend();
    }
}

final class FiberParamService
{
    public function __construct(
        public readonly string $locale,
    ) {}
}

final class FiberNestedProbe
{
    public function __construct(ContainerInterface $c)
    {
        $c->get('suspending');
    }
}
