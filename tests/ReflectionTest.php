<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Reflection;
use Kaly\Tests\Mocks\ReflTestMock;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestUnionClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

class ReflectionTest extends TestCase
{
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
}
