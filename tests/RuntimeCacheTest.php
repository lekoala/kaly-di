<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\RuntimeCache;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

interface RuntimeCacheTestInterface1 {}
interface RuntimeCacheTestInterface2 {}
class RuntimeCacheTestGrandparent {}
class RuntimeCacheTestParent extends RuntimeCacheTestGrandparent {}
class RuntimeCacheTestChild extends RuntimeCacheTestParent implements RuntimeCacheTestInterface1, RuntimeCacheTestInterface2 {}

class RuntimeCacheTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeCache::clear();
    }

    public function testReflection(): void
    {
        $result = RuntimeCache::reflection(RuntimeCacheTestChild::class);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        [$refl, $params] = $result;
        $this->assertInstanceOf(ReflectionClass::class, $refl);
        $this->assertEquals(RuntimeCacheTestChild::class, $refl->getName());
        $this->assertIsArray($params);

        // Test caching
        $result2 = RuntimeCache::reflection(RuntimeCacheTestChild::class);
        $this->assertSame($result, $result2);
    }

    public function testClassHierarchy(): void
    {
        $hierarchy = RuntimeCache::classHierarchy(RuntimeCacheTestChild::class);

        $this->assertArrayHasKey('interfaces', $hierarchy);
        $this->assertArrayHasKey('parents', $hierarchy);

        // Parents should be from closest to furthest
        $this->assertEquals([
            RuntimeCacheTestParent::class,
            RuntimeCacheTestGrandparent::class,
        ], $hierarchy['parents']);

        $interfaces = $hierarchy['interfaces'];

        $this->assertContains(RuntimeCacheTestInterface1::class, $interfaces);
        $this->assertContains(RuntimeCacheTestInterface2::class, $interfaces);

        // RuntimeCache::classHierarchy uses ksort on interfaces
        $expectedInterfaces = [RuntimeCacheTestInterface1::class, RuntimeCacheTestInterface2::class];
        sort($expectedInterfaces);
        $this->assertEquals($expectedInterfaces, $interfaces);

        // Test caching
        $hierarchy2 = RuntimeCache::classHierarchy(RuntimeCacheTestChild::class);
        $this->assertSame($hierarchy, $hierarchy2);
    }

    public function testClassExists(): void
    {
        $this->assertTrue(RuntimeCache::classExists(RuntimeCacheTestChild::class));
        $this->assertFalse(RuntimeCache::classExists('NonExistentClass'));
    }

    public function testTypeExists(): void
    {
        $this->assertTrue(RuntimeCache::typeExists(RuntimeCacheTestChild::class));
        $this->assertTrue(RuntimeCache::typeExists(RuntimeCacheTestInterface1::class));
        $this->assertFalse(RuntimeCache::typeExists('NonExistentType'));
    }

    public function testClear(): void
    {
        $result = RuntimeCache::reflection(RuntimeCacheTestChild::class);
        RuntimeCache::clear();

        $result2 = RuntimeCache::reflection(RuntimeCacheTestChild::class);
        $this->assertNotSame($result, $result2);
    }
}
