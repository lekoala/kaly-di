<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Definitions;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestNestedLeaf;
use Kaly\Tests\Mocks\TestNestedMiddle;
use Kaly\Tests\Mocks\TestNestedRoot;
use Kaly\Tests\Mocks\TestObject5;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ConfiguredParametersTest extends TestCase
{
    public function testConfiguredParametersAfterAClosureAreResolved(): void
    {
        $container = Definitions::create()
            ->parameter(TestObject5::class, 'v', fn(): string => 'from closure')
            ->parameter(TestObject5::class, 'v2', 'after closure')
            ->parameter(TestObject5::class, 'arr', ['configured'])
            ->parameter(TestObject5::class, 'v3', fn(): string => 'last closure')
            ->createContainer();

        $instance = $container->get(TestObject5::class);

        $this->assertInstanceOf(TestObject5::class, $instance);
        $this->assertSame('from closure', $instance->v);
        $this->assertSame('after closure', $instance->v2);
        $this->assertSame(['configured'], $instance->arr);
        $this->assertSame('last closure', $instance->v3);
    }

    public function testConfiguredClosureFailurePreservesTheNestedResolutionPath(): void
    {
        $failure = null;
        $container = Definitions::create()
            ->parameter(TestNestedRoot::class, 'middle', function (ContainerInterface $c) use (&$failure): object {
                try {
                    return $c->get(TestNestedMiddle::class);
                } catch (UnresolvableParameterException $e) {
                    $failure = $e;
                    throw $e;
                }
            })
            ->createContainer();

        try {
            $container->get(TestNestedRoot::class);
            $this->fail('Expected a nested resolution failure');
        } catch (UnresolvableParameterException $e) {
            $path =
                TestNestedRoot::class
                . '::$middle -> '
                . TestNestedMiddle::class
                . '::$leaf -> '
                . TestNestedLeaf::class
                . '::$apiKey';
            $this->assertInstanceOf(UnresolvableParameterException::class, $failure);
            $this->assertSame($failure, $e->getPrevious());
            $this->assertSame('middle', $e->getParameterName());
            $this->assertSame(TestNestedRoot::class, $e->getObjectId());
            $this->assertSame($path, $e->getResolutionPath());
            $this->assertStringContainsString('Path: ' . $path, $e->getMessage());
        }
    }
}
