<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class RecoveryBranch
{
    public bool $ready = false;

    public function __construct(
        public object $dependency,
    ) {}
}

final class RecoveryRoot
{
    public function __construct(
        public RecoveryBranch $a,
        public RecoveryBranch $b,
    ) {}
}

class ResolutionStateTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function failureStages(): array
    {
        return ['factory failure' => ['factory'], 'callback failure' => ['callback']];
    }

    #[DataProvider('failureStages')]
    public function testFailedGraphRetriesOnlyTheFailedBranch(string $stage): void
    {
        $builds = ['shared' => 0, 'a' => 0, 'b' => 0];
        $configurations = ['shared' => 0, 'a' => 0, 'b' => 0, 'root' => 0];
        $attempts = [];
        $cause = new LogicException('first attempt failed');
        $definitions = $this->graphDefinitions($stage, $cause, $builds, $configurations, $attempts);
        $container = $definitions->createContainer();

        try {
            $container->get(RecoveryRoot::class);
            $this->fail('Expected the first graph resolution to fail');
        } catch (ContainerException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }

        $this->assertSame(['shared' => 1, 'a' => 1, 'b' => 1], $builds);
        $this->assertSame(0, $configurations['root']);
        $shared = $container->get('shared');
        $a = $container->get('a');
        $this->assertInstanceOf(RecoveryBranch::class, $a);
        $this->assertTrue($a->ready);
        $this->assertSame($shared, $a->dependency);
        if ($stage === 'callback') {
            $this->assertFalse($attempts[0]->ready);
        }

        $root = $container->get(RecoveryRoot::class);
        $this->assertSame($a, $root->a);
        $this->assertSame($shared, $root->b->dependency);
        $this->assertTrue($root->b->ready);
        $this->assertSame($root->b, $container->get('b-alias'));
        $this->assertSame($root, $container->get(RecoveryRoot::class));
        $this->assertSame(['shared' => 1, 'a' => 1, 'b' => 2], $builds);
        $this->assertSame(
            [
                'shared' => 1,
                'a' => 1,
                'b' => $stage === 'callback' ? 2 : 1,
                'root' => 1,
            ],
            $configurations,
        );
        if ($stage === 'callback') {
            $this->assertNotSame($attempts[0], $root->b);
            $this->assertFalse($attempts[0]->ready);
        }
    }

    /**
     * @param array<string,int> $builds
     * @param array<string,int> $configurations
     * @param list<RecoveryBranch> $attempts
     */
    private function graphDefinitions(
        string $stage,
        LogicException $cause,
        array &$builds,
        array &$configurations,
        array &$attempts,
    ): Definitions {
        return Definitions::create()
            ->set('shared', function () use (&$builds): stdClass {
                $builds['shared']++;
                return new stdClass();
            })
            ->callback('shared', function () use (&$configurations): void {
                $configurations['shared']++;
            })
            ->set('a', function (ContainerInterface $c) use (&$builds): RecoveryBranch {
                $builds['a']++;
                return new RecoveryBranch($c->get('shared'));
            })
            ->callback('a', function (RecoveryBranch $branch) use (&$configurations): void {
                $configurations['a']++;
                $branch->ready = true;
            })
            ->set('b', function (ContainerInterface $c) use (&$builds, $stage, $cause): RecoveryBranch {
                $builds['b']++;
                $dependency = $c->get('shared');
                if ($stage === 'factory' && $builds['b'] === 1) {
                    throw $cause;
                }
                return new RecoveryBranch($dependency);
            })
            ->callback('b', function (RecoveryBranch $branch) use (&$configurations, &$attempts, $stage, $cause): void {
                $configurations['b']++;
                $attempts[] = $branch;
                if ($stage === 'callback' && $configurations['b'] === 1) {
                    throw $cause;
                }
                $branch->ready = true;
            })
            ->parameter(RecoveryRoot::class, 'a', fn(ContainerInterface $c): object => $c->get('a'))
            ->parameter(RecoveryRoot::class, 'b', fn(ContainerInterface $c): object => $c->get('b'))
            ->callback(RecoveryRoot::class, function () use (&$configurations): void {
                $configurations['root']++;
            })
            ->alias('b-alias', 'b');
    }
}
