<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

interface CombinedLeft {}

interface CombinedRight {}

interface CombinedAlternative {}

final class CombinedBoth implements CombinedLeft, CombinedRight {}

final class CombinedOther implements CombinedAlternative {}

final class CombinedLeftOnly implements CombinedLeft {}

class InheritedTypeBase {}

class InheritedTypeScope extends InheritedTypeBase
{
    public function __construct(
        public ?self $peer = null,
        public ?parent $ancestor = null,
    ) {}
}

final class InheritedTypeChild extends InheritedTypeScope {}

class CombinedTypesTest extends TestCase
{
    public function testInheritedConstructorResolvesSelfAndParentInTheDeclaringScope(): void
    {
        $peer = new InheritedTypeScope();
        $ancestor = new InheritedTypeBase();
        $container = Definitions::create()
            ->set(InheritedTypeScope::class, $peer)
            ->set(InheritedTypeBase::class, $ancestor)
            ->createContainer();

        $child = $container->get(InheritedTypeChild::class);
        $fresh = (new Injector($container))->make(InheritedTypeChild::class);

        $this->assertNotSame($child, $fresh);
        foreach ([$child, $fresh] as $instance) {
            $this->assertSame($peer, $instance->peer);
            $this->assertSame($ancestor, $instance->ancestor);
        }
    }

    public function testExplicitCombinedTypesAcceptEitherCompleteBranch(): void
    {
        $injector = new Injector(new Container());
        $callable = fn((CombinedLeft&CombinedRight)|CombinedAlternative $value): object => $value;

        foreach ([new CombinedBoth(), new CombinedOther()] as $value) {
            $this->assertSame($value, $injector->invoke($callable, value: $value));
        }
    }

    #[RequiresSetting('zend.assertions', '1')]
    public function testPartialIntersectionDoesNotSatisfyACombinedType(): void
    {
        $this->expectException(AssertionError::class);
        $this->expectExceptionMessage("parameter `value` doesn't support");
        (new Injector(new Container()))->invoke(
            fn((CombinedLeft&CombinedRight)|CombinedAlternative $value): object => $value,
            value: new CombinedLeftOnly(),
        );
    }

    #[DataProvider('variadicTypes')]
    public function testValidTypedVariadicsKeepTheirValuesAndOrder(string $type): void
    {
        $both = new CombinedBoth();
        $other = new CombinedOther();
        $values = $type === 'intersection' ? [$both, $both] : [$both, $other];
        $callable = match ($type) {
            'union' => fn(CombinedLeft|CombinedAlternative ...$values): array => $values,
            'intersection' => fn(CombinedLeft&CombinedRight ...$values): array => $values,
            default => fn((CombinedLeft&CombinedRight)|CombinedAlternative ...$values): array => $values,
        };
        $injector = new Injector(new Container());

        $this->assertSame($values, $injector->invoke($callable, ...$values));
        $this->assertSame($values, $injector->invoke($callable, values: [
            'first' => $values[0],
            'second' => $values[1],
        ]));
        $this->assertSame([], $injector->invoke($callable));
    }

    /** @return array<string,array{string}> */
    public static function variadicTypes(): array
    {
        return ['union' => ['union'], 'intersection' => ['intersection'], 'combined' => ['combined']];
    }

    #[RequiresSetting('zend.assertions', '1')]
    #[DataProvider('variadicTypes')]
    public function testInvalidTypedVariadicIsRejectedBeforeResolvingDependencies(string $type): void
    {
        $calls = 0;
        $container = Definitions::create()->set(InheritedTypeBase::class, function () use (&$calls): InheritedTypeBase {
            $calls++;
            return new InheritedTypeBase();
        })->createContainer();
        $callable = match ($type) {
            'union' => fn(InheritedTypeBase $dependency, CombinedLeft|CombinedAlternative ...$values): array => $values,
            'intersection' => fn(
                InheritedTypeBase $dependency,
                CombinedLeft&CombinedRight ...$values,
            ): array => $values,
            default => fn(
                InheritedTypeBase $dependency,
                (CombinedLeft&CombinedRight)|CombinedAlternative ...$values,
            ): array => $values,
        };

        try {
            (new Injector($container))->invoke($callable, values: [new CombinedBoth(), new InheritedTypeBase()]);
            $this->fail('Expected argument validation to reject the invalid variadic value');
        } catch (AssertionError $e) {
            $this->assertStringContainsString("parameter `values` doesn't support", $e->getMessage());
        } finally {
            $this->assertSame(0, $calls, 'Invalid explicit arguments must not run dependency factories');
        }
    }
}
