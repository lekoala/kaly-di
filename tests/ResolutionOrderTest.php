<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestAlternativeObject;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResolutionOrderTest extends TestCase
{
    /** @return iterable<string,array{list<string>,bool}> */
    public static function accessOrders(): iterable
    {
        $ids = [TestInterface::class, '\\' . strtolower(TestInterface::class), 'alias', 'alias-chain'];
        foreach (self::permutations($ids) as $index => $order) {
            yield "order {$index}, concrete first" => [$order, true];
            yield "order {$index}, concrete last" => [$order, false];
        }
    }

    /** @param list<string> $ids
     * @return list<list<string>>
     */
    private static function permutations(array $ids): array
    {
        if ($ids === []) {
            return [[]];
        }
        $orders = [];
        foreach ($ids as $index => $id) {
            $remaining = $ids;
            array_splice($remaining, $index, 1);
            foreach (self::permutations($remaining) as $tail) {
                $orders[] = [$id, ...$tail];
            }
        }
        return $orders;
    }

    /** @param list<string> $order */
    #[DataProvider('accessOrders')]
    public function testReboundAliasesShareAnInstanceInEveryAccessOrder(array $order, bool $concreteFirst): void
    {
        $originalCalls = 0;
        $replacementCalls = 0;
        $interfaceCallbacks = [];
        $classCallbacks = [];
        $original = function () use (&$originalCalls): TestObject {
            $originalCalls++;
            return new TestObject();
        };
        $definitions = Definitions::create()
            ->set(TestInterface::class, $original)
            ->alias('alias', TestInterface::class)
            ->alias('alias-chain', 'alias')
            ->callback(TestInterface::class, function (TestInterface $instance) use (&$interfaceCallbacks): void {
                $interfaceCallbacks[] = $instance;
            })
            ->callback(TestAlternativeObject::class, function (TestAlternativeObject $instance) use (
                &$classCallbacks,
            ): void {
                $classCallbacks[] = $instance;
            })
            ->rebind(
                TestInterface::class,
                function () use (&$replacementCalls): TestAlternativeObject {
                    $replacementCalls++;
                    return new TestAlternativeObject();
                },
                expected: $original,
            );
        $container = $definitions->createContainer();
        $concrete = $concreteFirst ? $container->get(TestAlternativeObject::class) : null;
        $shared = null;
        foreach ($order as $id) {
            $this->assertTrue($container->has($id));
            $instance = $container->get($id);
            $this->assertInstanceOf(TestAlternativeObject::class, $instance);
            $shared ??= $instance;
            $this->assertSame($shared, $instance);
        }
        $concrete ??= $container->get(TestAlternativeObject::class);

        $this->assertNotSame($shared, $concrete, 'A binding keeps its own instance, independently of the concrete id');
        $this->assertSame($concrete, $container->get(TestAlternativeObject::class));
        $expected = $concreteFirst ? [$concrete, $shared] : [$shared, $concrete];
        $this->assertSame($expected, $interfaceCallbacks);
        $this->assertSame($expected, $classCallbacks);
        $this->assertSame(0, $originalCalls);
        $this->assertSame(1, $replacementCalls);
    }
}
