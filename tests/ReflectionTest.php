<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Injector;
use Kaly\Di\Reflection;
use Kaly\Tests\Mocks\ReflTestMock;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestUnionClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;

trait ReflectionTestTrait {}

enum ReflectionTestEnum
{
    case A;
}

class ReflectionTest extends TestCase
{
    public function testTryClass(): void
    {
        $refl = Reflection::tryClass(TestObject::class);
        $this->assertInstanceOf(ReflectionClass::class, $refl);
        $this->assertSame(TestObject::class, $refl->getName());

        // Cached: same reflection, whatever the spelling
        $this->assertSame($refl, Reflection::tryClass(TestObject::class));
        $this->assertSame($refl, Reflection::tryClass(strtolower(TestObject::class)));
        $this->assertSame($refl, Reflection::tryClass('\\' . strtoupper(TestObject::class)));
    }

    public function testTryClassOnlyFindsClasses(): void
    {
        $this->assertNull(Reflection::tryClass('Kaly\\Tests\\NoSuchClass'));
        $this->assertNull(Reflection::tryClass(TestInterface::class));
        $this->assertNull(Reflection::tryClass(ReflectionTestTrait::class));
        $this->assertSame(ReflectionTestEnum::class, Reflection::tryClass(ReflectionTestEnum::class)?->getName());
    }

    /**
     * The answer does not depend on what the container already reflected.
     */
    public function testTryClassIgnoresCachedInterfaces(): void
    {
        // Caches the interface reflection, then refuses to build it
        try {
            (new Injector(new Container()))->make(TestInterface::class);
            $this->fail('An interface cannot be built');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not instantiable', $e->getMessage());
        }

        $this->assertNull(Reflection::tryClass(TestInterface::class));
    }

    /**
     * A failed lookup is not cached: a class that becomes loadable later is
     * found.
     */
    public function testTryClassFindsAClassLoadedLater(): void
    {
        $class = 'KalyLateLoaded\\LateLoadedClass';
        $enabled = false;
        $loader = static function (string $name) use ($class, &$enabled): void {
            if ($enabled && $name === $class) {
                require __DIR__ . '/fixtures/LateLoadedClass.php';
            }
        };
        spl_autoload_register($loader);

        try {
            $this->assertNull(Reflection::tryClass($class));
            $enabled = true;
            $this->assertSame($class, Reflection::tryClass($class)?->getName());
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testTryClassPropagatesAutoloaderErrors(): void
    {
        $loader = static function (string $name): void {
            if ($name === 'KalyBrokenAutoload\\Broken') {
                throw new RuntimeException('autoloader failed');
            }
        };
        spl_autoload_register($loader);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('autoloader failed');
            Reflection::tryClass('KalyBrokenAutoload\\Broken');
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testGetClassName(): void
    {
        $this->assertSame(stdClass::class, Reflection::getClassName(stdClass::class));
        $this->assertSame(stdClass::class, Reflection::getClassName(new stdClass()));
        $this->assertSame(ReflTestMock::class, Reflection::getClassName(new ReflTestMock()));
    }

    public function testGetShortClassName(): void
    {
        $this->assertSame('stdClass', Reflection::getShortClassName(stdClass::class));
        $this->assertSame('stdClass', Reflection::getShortClassName(new stdClass()));
        $this->assertSame('ReflTestMock', Reflection::getShortClassName(ReflTestMock::class));
        $this->assertSame('ReflTestMock', Reflection::getShortClassName(new ReflTestMock()));
        $this->assertSame('Container', Reflection::getShortClassName('Kaly\\Di\\Container'));
    }

    public function testGetClassNamespace(): void
    {
        $this->assertSame('', Reflection::getClassNamespace(stdClass::class));
        $this->assertSame('', Reflection::getClassNamespace(new stdClass()));
        $this->assertSame('Kaly\\Tests\\Mocks', Reflection::getClassNamespace(ReflTestMock::class));
        $this->assertSame('Kaly\\Tests\\Mocks', Reflection::getClassNamespace(new ReflTestMock()));
        $this->assertSame('Kaly\\Di', Reflection::getClassNamespace('Kaly\\Di\\Container'));
    }

    public function testGetParameterClass(): void
    {
        $method = (new ReflectionClass(ReflTestMock::class))->getMethod('someMethod');
        $params = $method->getParameters();

        // string|int union: no class
        $this->assertNull(Reflection::getParameterClass($params[0]));
        // untyped: no class
        $this->assertNull(Reflection::getParameterClass($params[1]));
        // stdClass: class found
        $class = Reflection::getParameterClass($params[2]);
        $this->assertInstanceOf(ReflectionClass::class, $class);
        $this->assertSame(stdClass::class, $class->getName());
        // intersection: no single class
        $this->assertNull(Reflection::getParameterClass($params[3]));
        // ?stdClass nullable: class found
        $nullable = Reflection::getParameterClass($params[4]);
        $this->assertInstanceOf(ReflectionClass::class, $nullable);
        $this->assertSame(stdClass::class, $nullable->getName());
        // int builtin: no class
        $this->assertNull(Reflection::getParameterClass($params[5]));
    }

    public function testGetParameterClassUnionWithClass(): void
    {
        $constructor = (new ReflectionClass(TestUnionClass::class))->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();

        $class = Reflection::getParameterClass($params[0]);
        $this->assertInstanceOf(ReflectionClass::class, $class);
        $this->assertSame(TestObject::class, $class->getName());
    }

    public function testGetParameterClassBuiltin(): void
    {
        $method = (new ReflectionClass(ReflTestMock::class))->getMethod('methodWithBuiltinTypes');
        $params = $method->getParameters();

        // mixed, iterable, callable, object, bool, int, float, string, array, false, true, null
        foreach ($params as $param) {
            $this->assertNull(Reflection::getParameterClass($param));
        }
    }

    public function testGetParameterTypeName(): void
    {
        $method = (new ReflectionClass(ReflTestMock::class))->getMethod('someMethod');
        $params = $method->getParameters();

        // string|int union: no class name
        $this->assertNull(Reflection::getParameterTypeName($params[0]));
        // untyped: no class name
        $this->assertNull(Reflection::getParameterTypeName($params[1]));
        // stdClass
        $this->assertSame(stdClass::class, Reflection::getParameterTypeName($params[2]));
        // intersection: no single name
        $this->assertNull(Reflection::getParameterTypeName($params[3]));
        // ?stdClass nullable
        $this->assertSame(stdClass::class, Reflection::getParameterTypeName($params[4]));
        // int builtin
        $this->assertNull(Reflection::getParameterTypeName($params[5]));

        // union with a class member: first non-builtin wins
        $constructor = (new ReflectionClass(TestUnionClass::class))->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertSame(TestObject::class, Reflection::getParameterTypeName($constructor->getParameters()[0]));
    }
}
