<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\CircularReferenceException;
use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ReentrantService
{
    public bool $ready = false;

    public function __construct(
        public string $value,
    ) {}
}

class CaughtCircularReferenceTest extends TestCase
{
    public function testFailureAfterACaughtCycleCanBeRetriedWithAFreshInstance(): void
    {
        $instances = [];
        $rejections = 0;
        $cause = new LogicException('configuration failed after catching the cycle');
        $definitions = Definitions::create()
            ->set(ReentrantService::class, function () use (&$instances): ReentrantService {
                if (count($instances) >= 2) {
                    throw new LogicException('Unexpected recursive construction');
                }
                $instance = new ReentrantService('configured');
                $instances[] = $instance;
                return $instance;
            })
            ->callback(ReentrantService::class, function (ReentrantService $instance, ContainerInterface $c) use (
                &$instances,
                &$rejections,
                $cause,
            ): void {
                foreach ([1, 2] as $attempt) {
                    try {
                        $c->get('alias');
                        $this->fail("Recursive attempt {$attempt} should be rejected");
                    } catch (CircularReferenceException) {
                        $rejections++;
                    }
                }
                if (count($instances) === 1) {
                    throw $cause;
                }
                $instance->ready = true;
            })
            ->alias('alias', ReentrantService::class);
        $container = $definitions->createContainer();

        try {
            $container->get('alias');
            $this->fail('Expected configuration to fail');
        } catch (ContainerException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
        $this->assertCount(1, $instances);
        $this->assertFalse($instances[0]->ready);
        $this->assertSame(2, $rejections);

        $instance = $container->get('alias');
        $this->assertInstanceOf(ReentrantService::class, $instance);
        $this->assertTrue($instance->ready);
        $this->assertSame($instances[1], $instance);
        $this->assertNotSame($instances[0], $instance);
        $this->assertFalse($instances[0]->ready);
        $this->assertSame($instance, $container->get(ReentrantService::class));
        $this->assertCount(2, $instances);
        $this->assertSame(4, $rejections);
    }

    /** @return array<string,array{string,string}> */
    public static function recursiveLookups(): array
    {
        $cases = [];
        foreach (['factory', 'parameter', 'callback'] as $stage) {
            foreach (['direct', 'alias', 'case'] as $spelling) {
                $cases[$stage . ' via ' . $spelling] = [$stage, $spelling];
            }
        }
        return $cases;
    }

    #[DataProvider('recursiveLookups')]
    public function testCaughtCycleDoesNotClearTheOuterGuard(string $stage, string $spelling): void
    {
        $lookup = match ($spelling) {
            'alias' => 'indirect-alias',
            'case' => '\\' . strtolower(ReentrantService::class),
            default => ReentrantService::class,
        };
        $builds = 0;
        $configurations = 0;
        $probes = 0;
        $rejections = 0;
        $probe = function (ContainerInterface $c) use ($lookup, &$probes, &$rejections): void {
            // Bound the test even if a regression permits recursive entry.
            if (++$probes > 1) {
                throw new LogicException('Recursive resolution entered the service twice');
            }
            foreach ([1, 2] as $attempt) {
                try {
                    $c->get($lookup);
                    $this->fail("Recursive attempt {$attempt} should be rejected");
                } catch (CircularReferenceException $e) {
                    $rejections++;
                    $this->assertStringContainsString(
                        'Circular reference to `' . ReentrantService::class . '`',
                        $e->getMessage(),
                    );
                }
            }
        };
        $definitions = Definitions::create()
            ->set(ReentrantService::class, function (ContainerInterface $c) use (&$builds, $stage, $probe): string {
                if (++$builds > 1) {
                    throw new LogicException('Recursive resolution ran the factory twice');
                }
                if ($stage === 'factory') {
                    $probe($c);
                }
                return ReentrantService::class;
            })
            ->parameter(ReentrantService::class, 'value', function (ContainerInterface $c) use (
                $stage,
                $probe,
            ): string {
                if ($stage === 'parameter') {
                    $probe($c);
                }
                return 'configured';
            })
            ->callback(ReentrantService::class, function (ReentrantService $instance, ContainerInterface $c) use (
                &$configurations,
                $stage,
                $probe,
            ): void {
                $configurations++;
                if ($stage === 'callback') {
                    $probe($c);
                }
                $instance->ready = true;
            })
            ->alias('direct-alias', ReentrantService::class)
            ->alias('indirect-alias', 'direct-alias');
        $container = $definitions->createContainer();

        $instance = $container->get(ReentrantService::class);

        $this->assertTrue($instance->ready);
        $this->assertSame('configured', $instance->value);
        $this->assertSame($instance, $container->get($lookup));
        $this->assertSame(2, $rejections);
        $this->assertSame(1, $probes);
        $this->assertSame(1, $builds);
        $this->assertSame(1, $configurations);
    }
}
