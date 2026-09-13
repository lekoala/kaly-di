<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\ReflectionCache;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

interface ReflectionCacheTestInterface1 {}

interface ReflectionCacheTestInterface2 {}

class ReflectionCacheTestGrandparent {}

class ReflectionCacheTestParent extends ReflectionCacheTestGrandparent {}

class ReflectionCacheTestChild extends ReflectionCacheTestParent implements
    ReflectionCacheTestInterface1,
    ReflectionCacheTestInterface2 {}

abstract class ReflectionCacheTestAbstract {}

enum ReflectionCacheTestEnum
{
    case A;
}

class ReflectionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ReflectionCache::clear();
    }

    public function testReflection(): void
    {
        $result = ReflectionCache::reflection(ReflectionCacheTestChild::class);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        [$refl, $params] = $result;
        $this->assertInstanceOf(ReflectionClass::class, $refl);
        $this->assertEquals(ReflectionCacheTestChild::class, $refl->getName());
        $this->assertIsArray($params);

        // Test caching
        $result2 = ReflectionCache::reflection(ReflectionCacheTestChild::class);
        $this->assertSame($result, $result2);
    }

    public function testClassHierarchy(): void
    {
        $hierarchy = ReflectionCache::classHierarchy(ReflectionCacheTestChild::class);

        $this->assertArrayHasKey('interfaces', $hierarchy);
        $this->assertArrayHasKey('parents', $hierarchy);

        // Parents should be from closest to furthest
        $this->assertEquals(
            [
                ReflectionCacheTestParent::class,
                ReflectionCacheTestGrandparent::class,
            ],
            $hierarchy['parents'],
        );

        $interfaces = $hierarchy['interfaces'];

        $this->assertContains(ReflectionCacheTestInterface1::class, $interfaces);
        $this->assertContains(ReflectionCacheTestInterface2::class, $interfaces);

        // ReflectionCache::classHierarchy uses ksort on interfaces
        $expectedInterfaces = [ReflectionCacheTestInterface1::class, ReflectionCacheTestInterface2::class];
        sort($expectedInterfaces);
        $this->assertEquals($expectedInterfaces, $interfaces);

        // Test caching
        $hierarchy2 = ReflectionCache::classHierarchy(ReflectionCacheTestChild::class);
        $this->assertSame($hierarchy, $hierarchy2);
    }

    public function testIsInstantiable(): void
    {
        $this->assertTrue(ReflectionCache::isInstantiable(ReflectionCacheTestChild::class));
        $this->assertFalse(ReflectionCache::isInstantiable(ReflectionCacheTestInterface1::class));
        $this->assertFalse(ReflectionCache::isInstantiable(ReflectionCacheTestAbstract::class));
        $this->assertFalse(ReflectionCache::isInstantiable(ReflectionCacheTestEnum::class));
        $this->assertFalse(ReflectionCache::isInstantiable('NonExistentClass'));
    }

    public function testClassHierarchyUnknownClassIsNotCached(): void
    {
        $unknown = 'NonExistentHierarchyClass';
        $expected = ['interfaces' => [], 'parents' => []];

        $this->assertSame($expected, ReflectionCache::classHierarchy($unknown));
        $this->assertSame($expected, ReflectionCache::classHierarchy($unknown));

        $prop = new ReflectionProperty(ReflectionCache::class, 'hierarchy');
        $hierarchy = $prop->getValue();
        $this->assertIsArray($hierarchy);
        $this->assertArrayNotHasKey($unknown, $hierarchy);
    }

    public function testClear(): void
    {
        $result = ReflectionCache::reflection(ReflectionCacheTestChild::class);
        ReflectionCache::clear();

        $result2 = ReflectionCache::reflection(ReflectionCacheTestChild::class);
        $this->assertNotSame($result, $result2);
    }
}
