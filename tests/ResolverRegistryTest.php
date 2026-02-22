<?php

declare(strict_types=1);

namespace Kaly\Tests;

use PHPUnit\Framework\TestCase;
use Kaly\Di\ResolverRegistry;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject5;

class ResolverRegistryTest extends TestCase
{
    public function testResolveNameByExactParameterMatch()
    {
        $registry = new ResolverRegistry();
        $registry->resolve(TestObject::class, 'myParam', 'service_1');

        // Exact match should return 'service_1'
        $result = $registry->resolveName('myParam', TestObject::class, self::class);
        $this->assertEquals('service_1', $result);
    }

    public function testResolveNameByContext()
    {
        $registry = new ResolverRegistry();
        // When TestObject5 asks for TestInterface, give it 'service_2'
        $registry->resolve(TestInterface::class, TestObject5::class, 'service_2');

        $result = $registry->resolveName('anyParamName', TestInterface::class, TestObject5::class);
        $this->assertEquals('service_2', $result);
    }

    public function testResolveNameByContextUsingCallback()
    {
        $registry = new ResolverRegistry();
        $registry->resolve(TestInterface::class, TestObject5::class, function ($name, $class) {
            return 'dynamic_' . $name;
        });

        $result = $registry->resolveName('someParam', TestInterface::class, TestObject5::class);
        $this->assertEquals('dynamic_someParam', $result);
    }

    public function testResolveNameByWildcard()
    {
        $registry = new ResolverRegistry();
        $registry->resolveAll(TestInterface::class, function ($name, $class) {
            return 'fallback_service';
        });

        $result = $registry->resolveName('unknownParam', TestInterface::class, self::class);
        $this->assertEquals('fallback_service', $result);
    }

    public function testResolveNameReturnsNullWhenNoMatch()
    {
        $registry = new ResolverRegistry();
        $registry->resolve(TestObject::class, 'myParam', 'service_1');

        $result = $registry->resolveName('otherParam', TestObject::class, self::class);
        $this->assertNull($result);
    }

    public function testSortResolvers()
    {
        $registry = new ResolverRegistry();
        $registry->resolve(TestObject::class, 'a', '1');
        $registry->resolve(TestInterface::class, 'b', '2');

        $registry->sort();

        $keys = array_keys($registry->getResolvers());
        $this->assertEquals(TestInterface::class, $keys[0]);
        $this->assertEquals(TestObject::class, $keys[1]);
    }
}
