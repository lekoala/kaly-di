<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\Container;
use Kaly\Di\Refl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use stdClass;
use Kaly\Tests\Mocks\ReflTestMockObject;
use Kaly\Tests\Mocks\ReflTestMock;
use PDO;

class ReflTest extends TestCase
{
    public function testResolveConstructor(): void
    {
        $reflection = new ReflectionClass(PDO::class);
        $parameters = Refl::resolveParameters($reflection->getConstructor()->getParameters(), []);
        $this->assertEquals(['dsn', 'username', 'password', 'options'], array_keys($parameters));
    }

    public function testGetParameterTypes(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('someMethod');

        // Test with union type string|int $union,
        $param = $method->getParameters()[0];
        $types = Refl::getParameterTypes($param);
        $this->assertCount(2, $types);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[0]);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[1]);
        $this->assertSame('string', $types[0]->getName());
        $this->assertSame('int', $types[1]->getName());

        // Test with no type
        $param = $method->getParameters()[1];
        $types = Refl::getParameterTypes($param);
        $this->assertCount(0, $types);

        // Test with named type
        $param = $method->getParameters()[2];
        $types = Refl::getParameterTypes($param);
        $this->assertCount(1, $types);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[0]);
        $this->assertSame('stdClass', $types[0]->getName());

        // Test with intersection type
        $param = $method->getParameters()[3];
        $types = Refl::getParameterTypes($param);
        $this->assertCount(1, $types);
        $this->assertInstanceOf(ReflectionIntersectionType::class, $types[0]);
    }

    public function testValueMatchType(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('someMethod');

        // Test with union type
        $param = $method->getParameters()[0];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionUnionType::class, $type);

        $this->assertTrue(Refl::valueMatchType('test', $type));
        $this->assertTrue(Refl::valueMatchType(123, $type));
        $this->assertFalse(Refl::valueMatchType(true, $type));

        // Test with no type
        $param = $method->getParameters()[1];
        $type = $param->getType();
        $this->assertNull($type);
        $this->assertTrue(Refl::valueMatchType('test', $type));
        $this->assertTrue(Refl::valueMatchType(123, $type));
        $this->assertTrue(Refl::valueMatchType(true, $type));
        $this->assertTrue(Refl::valueMatchType(null, $type));

        // Test with named type
        $param = $method->getParameters()[2];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(Refl::valueMatchType(new stdClass(), $type));
        $this->assertFalse(Refl::valueMatchType('test', $type));

        // Test with named type + null
        $param = $method->getParameters()[4];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(Refl::valueMatchType(new stdClass(), $type));
        $this->assertTrue(Refl::valueMatchType(null, $type));
        $this->assertFalse(Refl::valueMatchType('test', $type));

        // Test with named type + builtin
        $param = $method->getParameters()[5];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(Refl::valueMatchType(123, $type));
        $this->assertFalse(Refl::valueMatchType('test', $type));

        // Test with IntersectionType
        $param = $method->getParameters()[3];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionIntersectionType::class, $type);
        $this->assertTrue(Refl::valueMatchType(new ReflTestMockObject(), $type));
        $this->assertFalse(Refl::valueMatchType(new stdClass(), $type));
    }

    public function testResolveParametersPositional(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Test with positional arguments
        $arguments = ['test', 123, true];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, [], 0, false, null, null], $resolved);

        // Test with too many positional arguments - it will throw an AssertionError
        $this->expectException(AssertionError::class);
        $arguments = ['test', 123, true, 'extra'];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, 'extra', 0, false, null, null], $resolved);

        // Test with variadic
        $arguments = ['test', 123, true, 'extra', 'extra2'];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, 'extra', 'extra2'], $resolved);
    }

    public function testResolveParametersNamed(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Test with named arguments
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => 'test',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);

        // Test with extra named arguments (ignored)
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true, 'extra' => 'extra'];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => 'test',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);

        //Test with default null
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true, 'param7' => null];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => 'test',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);
    }

    public function testResolveParametersMixed(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Cannot mix named/positional in an array => here, param1 stays empty
        $arguments = ['test', 'param2' => 123, 'param3' => true];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => '',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);

        // Test with named arguments
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => 'test',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);

        // Return order is based on the actual method, regardless of how you passed them
        $arguments = ['param1' => 'test', 'param3' => true, 'param2' => 123];
        $resolved = Refl::resolveParameters($parameters, $arguments);
        $this->assertSame([
            'param1' => 'test',
            'param2' => 123,
            'param3' => true,
            'param4' => [],
            'param5' => 0,
            'param6' => false,
            'param7' => null,
            'param8' => null
        ], $resolved);
    }

    public function testResolveParametersWithContainer(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithContainer');
        $parameters = $method->getParameters();

        // Create a dummy container for testing
        $container = $this->createMock(Container::class);
        $container->expects($this->once())
            ->method('has')
            ->willReturnMap([
                ['stdClass', true],
                ['string', false] // Builtin value must not be considered
            ]);
        $container->expects($this->once())
            ->method('get')
            ->with('stdClass')
            ->willReturn(new stdClass());

        // Test with container resolving
        $resolved = Refl::resolveParameters($parameters, [], $container);
        $this->assertArrayHasKey('param1', $resolved);
        $this->assertInstanceOf(stdClass::class, $resolved['param1']);
        $this->assertArrayHasKey('param2', $resolved);
        $this->assertEquals('', $resolved['param2']);
    }

    public function testDefaultTypeValue(): void
    {
        $reflector = new ReflectionClass(ReflTestMock::class);
        $method = $reflector->getMethod('methodWithDefaultTypes');
        $parameters = $method->getParameters();
        // Test with different types
        $this->assertSame([], Refl::defaultTypeValue($parameters[0]->getType())); // array
        $this->assertSame('', Refl::defaultTypeValue($parameters[1]->getType())); // string
        $this->assertSame(false, Refl::defaultTypeValue($parameters[2]->getType())); // bool
        $this->assertSame(0, Refl::defaultTypeValue($parameters[3]->getType())); // int
        $this->assertSame(0.0, Refl::defaultTypeValue($parameters[4]->getType())); // float
        $this->assertNull(Refl::defaultTypeValue($parameters[5]->getType())); // other
        $this->assertNull(Refl::defaultTypeValue(null)); // null
    }
}
