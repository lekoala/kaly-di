<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Parameters;
use Kaly\Di\TypeMatcher;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\ReflTestMock;
use Kaly\Tests\Mocks\ReflTestMockObject;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use stdClass;

class ParametersTest extends TestCase
{
    public function testResolveConstructorThrowsOnMissingRequired(): void
    {
        $reflection = new ReflectionClass(PDO::class);
        $this->expectException(UnresolvableParameterException::class);
        Parameters::resolveParameters($reflection->getConstructor()->getParameters(), []);
    }

    public function testResolveConstructorDefaultsOptionalParameters(): void
    {
        $reflection = new ReflectionClass(PDO::class);
        $parameters = Parameters::resolveParameters($reflection->getConstructor()->getParameters(), [
            'dsn' => 'sqlite::memory:',
        ]);

        $this->assertSame(['sqlite::memory:', null, null, null], $parameters);
    }

    public function testGetParameterTypes(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('someMethod');

        // Test with union type string|int $union,
        $param = $method->getParameters()[0];
        $types = Parameters::getParameterTypes($param);
        $this->assertCount(2, $types);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[0]);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[1]);
        $this->assertSame('string', $types[0]->getName());
        $this->assertSame('int', $types[1]->getName());

        // Test with no type
        $param = $method->getParameters()[1];
        $types = Parameters::getParameterTypes($param);
        $this->assertCount(0, $types);

        // Test with named type
        $param = $method->getParameters()[2];
        $types = Parameters::getParameterTypes($param);
        $this->assertCount(1, $types);
        $this->assertInstanceOf(ReflectionNamedType::class, $types[0]);
        $this->assertSame('stdClass', $types[0]->getName());

        // Test with intersection type
        $param = $method->getParameters()[3];
        $types = Parameters::getParameterTypes($param);
        $this->assertCount(1, $types);
        $this->assertInstanceOf(ReflectionIntersectionType::class, $types[0]);
    }

    public function testTypeMatcherBuiltins(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithBuiltinTypes');
        $params = $method->getParameters();

        // mixed
        $this->assertTrue(TypeMatcher::matches(1, $params[0]->getType()));
        $this->assertTrue(TypeMatcher::matches('test', $params[0]->getType()));

        // iterable
        $this->assertTrue(TypeMatcher::matches([], $params[1]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[1]->getType()));

        // callable
        $this->assertTrue(TypeMatcher::matches(function () {}, $params[2]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[2]->getType()));

        // object
        $this->assertTrue(TypeMatcher::matches(new stdClass(), $params[3]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[3]->getType()));

        // bool
        $this->assertTrue(TypeMatcher::matches(true, $params[4]->getType()));
        $this->assertTrue(TypeMatcher::matches(false, $params[4]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[4]->getType()));

        // int
        $this->assertTrue(TypeMatcher::matches(1, $params[5]->getType()));
        $this->assertFalse(TypeMatcher::matches(1.1, $params[5]->getType()));

        // float (int is widened to float, like PHP does)
        $this->assertTrue(TypeMatcher::matches(1.1, $params[6]->getType()));
        $this->assertTrue(TypeMatcher::matches(1, $params[6]->getType()));
        $this->assertFalse(TypeMatcher::matches('1', $params[6]->getType()));

        // string
        $this->assertTrue(TypeMatcher::matches('test', $params[7]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[7]->getType()));

        // array
        $this->assertTrue(TypeMatcher::matches([], $params[8]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[8]->getType()));

        // false
        $this->assertTrue(TypeMatcher::matches(false, $params[9]->getType()));
        $this->assertFalse(TypeMatcher::matches(true, $params[9]->getType()));

        // true
        $this->assertTrue(TypeMatcher::matches(true, $params[10]->getType()));
        $this->assertFalse(TypeMatcher::matches(false, $params[10]->getType()));

        // null
        $this->assertTrue(TypeMatcher::matches(null, $params[11]->getType()));
        $this->assertFalse(TypeMatcher::matches(1, $params[11]->getType()));
    }

    public function testTypeMatcherMatches(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('someMethod');

        // Test with union type
        $param = $method->getParameters()[0];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionUnionType::class, $type);

        $this->assertTrue(TypeMatcher::matches('test', $type));
        $this->assertTrue(TypeMatcher::matches(123, $type));
        $this->assertFalse(TypeMatcher::matches(true, $type));

        // Test with no type
        $param = $method->getParameters()[1];
        $type = $param->getType();
        $this->assertNull($type);
        $this->assertTrue(TypeMatcher::matches('test', $type));
        $this->assertTrue(TypeMatcher::matches(123, $type));
        $this->assertTrue(TypeMatcher::matches(true, $type));
        $this->assertTrue(TypeMatcher::matches(null, $type));

        // Test with named type
        $param = $method->getParameters()[2];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(TypeMatcher::matches(new stdClass(), $type));
        $this->assertFalse(TypeMatcher::matches('test', $type));

        // Test with named type + null
        $param = $method->getParameters()[4];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(TypeMatcher::matches(new stdClass(), $type));
        $this->assertTrue(TypeMatcher::matches(null, $type));
        $this->assertFalse(TypeMatcher::matches('test', $type));

        // Test with named type + builtin
        $param = $method->getParameters()[5];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertTrue(TypeMatcher::matches(123, $type));
        $this->assertFalse(TypeMatcher::matches('test', $type));

        // Test with IntersectionType
        $param = $method->getParameters()[3];
        $type = $param->getType();
        $this->assertInstanceOf(ReflectionIntersectionType::class, $type);
        $this->assertTrue(TypeMatcher::matches(new ReflTestMockObject(), $type));
        $this->assertFalse(TypeMatcher::matches(new stdClass(), $type));
    }

    public function testResolveParametersPositional(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Test with positional arguments
        $arguments = ['test', 123, true];
        $resolved = Parameters::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, [], 0, false, null, null], $resolved);
    }

    public function testResolveParametersTooManyPositionalArgumentsThrows(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Fourth positional argument lands on the array param => AssertionError
        $this->expectException(AssertionError::class);
        Parameters::resolveParameters($parameters, ['test', 123, true, 'extra']);
    }

    public function testResolveParametersPositionalVariadic(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Extra positional arguments after the fixed ones feed the variadic
        $arguments = ['test', 123, true, [], 0, false, null, null, 'extra', 'extra2'];
        $resolved = Parameters::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, [], 0, false, null, null, 'extra', 'extra2'], $resolved);
    }

    public function testResolveParametersNamed(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Named arguments still produce the final positional list
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true];
        $resolved = Parameters::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, [], 0, false, null, null], $resolved);

        // Return order is based on the actual signature, regardless of input order
        $arguments = ['param1' => 'test', 'param3' => true, 'param2' => 123];
        $resolved = Parameters::resolveParameters($parameters, $arguments);
        $this->assertSame(['test', 123, true, [], 0, false, null, null], $resolved);

        // Unknown named arguments are rejected (before any resolution)
        $arguments = ['param1' => 'test', 'param2' => 123, 'param3' => true, 'extra' => 'extra'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown named argument\(s\): `extra`/');
        Parameters::resolveParameters($parameters, $arguments);
    }

    public function testResolveParametersMixedPositionalAndNamed(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Positional prefix + named arguments, as in a PHP call
        $arguments = [0 => 'test', 1 => 123, 'param3' => true];
        $resolved = Parameters::resolveParameters($parameters, $arguments);

        $this->assertSame(['test', 123, true, [], 0, false, null, null], $resolved);
    }

    public function testResolveParametersRejectsDoubleAssignment(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/`param1` is provided both positionally/');
        Parameters::resolveParameters($parameters, [0 => 'test', 'param1' => 'other']);
    }

    public function testResolveParametersRejectsDoubleAssignmentOnVariadic(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        $arguments = ['test', 123, true, [], 0, false, null, null, 'extra'];
        $arguments['variadic'] = ['named'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Variadic parameter `variadic` is provided both/');
        Parameters::resolveParameters($parameters, $arguments);
    }

    public function testResolveParametersRejectsSurplusPositionalArguments(): void
    {
        $fn = fn(string $a, string $b): string => $a . $b;
        $parameters = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Too many positional arguments: expected at most 2, got 3/');
        Parameters::resolveParameters($parameters, ['a', 'b', 'c']);
    }

    public function testResolveParametersRejectsPositionalAfterNamed(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Positional arguments must be provided before named/');
        Parameters::resolveParameters($parameters, ['param1' => 'test', 0 => 'other']);
    }

    public function testInvalidArgumentsNeverTouchTheContainer(): void
    {
        $fn = fn(string $a, string $b): string => $a . $b;
        $parameters = (new ReflectionFunction($fn))->getParameters();

        $container = $this->createMock(Container::class);
        $container->expects($this->never())->method('has');
        $container->expects($this->never())->method('get');

        // Unknown named argument, surplus positional and double assignment at
        // once: rejected up front, before any dependency resolution
        $this->expectException(InvalidArgumentException::class);
        Parameters::resolveParameters(
            $parameters,
            [0 => 'x', 1 => 'y', 2 => 'z', 'a' => 'dup', 'unknown' => 'u'],
            $container,
        );
    }

    public function testArgumentValidationWorksWithAssertionsDisabled(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'zend.assertions=-1', __DIR__ . '/fixtures/unknown_arguments_assertions_disabled.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testResolveParametersMissingRequiredThrows(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        $this->expectException(UnresolvableParameterException::class);
        Parameters::resolveParameters($parameters, ['param2' => 123]);
    }

    public function testResolveParametersWithContainer(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithContainer');
        $parameters = $method->getParameters();

        // Create a dummy container for testing
        $container = $this->createMock(Container::class);
        $container
            ->expects($this->once())
            ->method('has')
            ->willReturnMap([
                ['stdClass', true],
                ['string', false], // Builtin value must not be considered
            ]);
        $container->expects($this->once())->method('get')->with('stdClass')->willReturn(new stdClass());

        // Test with container resolving
        $resolved = Parameters::resolveParameters($parameters, [], $container);
        $this->assertInstanceOf(stdClass::class, $resolved[0]);
        $this->assertNull($resolved[1]);
    }

    public function testResolveParametersProvidesTheCurrentContainer(): void
    {
        $container = $this->createStub(Container::class);
        $fn = fn(ContainerInterface $c): ContainerInterface => $c;
        $parameters = (new ReflectionFunction($fn))->getParameters();

        $resolved = Parameters::resolveParameters($parameters, [], $container);

        $this->assertSame([$container], $resolved);
    }

    public function testResolveParametersWithoutContainerCannotResolveContainerInterface(): void
    {
        $fn = fn(ContainerInterface $c): ContainerInterface => $c;
        $parameters = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(UnresolvableParameterException::class);
        Parameters::resolveParameters($parameters, []);
    }

    /**
     * Tests resolving parameters when a variadic parameter is passed as a named argument (array).
     */
    public function testResolveParametersNamedVariadic(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        // Define named arguments, including the variadic parameter by name
        $arguments = [
            'param1' => 'value1',
            'param2' => 101,
            'param3' => true,
            'variadic' => ['var_a', 'var_b', 'var_c'],
        ];

        $resolved = Parameters::resolveParameters($parameters, $arguments);

        $this->assertSame(['value1', 101, true, [], 0, false, null, null, 'var_a', 'var_b', 'var_c'], $resolved);
    }

    /**
     * A named variadic array may carry keys (as from array_combine or a map):
     * keys are discarded so the final list stays pure (no ArgumentCountError
     * when unpacking into array_push/reflection).
     */
    public function testResolveParametersNamedVariadicWithAssociativeKeys(): void
    {
        $fn = fn(string ...$names): array => $names;
        $parameters = (new ReflectionFunction($fn))->getParameters();

        $resolved = Parameters::resolveParameters($parameters, ['names' => ['first' => 'a', 'second' => 'b']]);

        $this->assertSame(['a', 'b'], $resolved);
    }

    /**
     * Tests that providing a non-array value to a named variadic argument throws an error.
     */
    public function testResolveParametersNamedVariadicInvalidType(): void
    {
        $reflection = new ReflectionClass(ReflTestMock::class);
        $method = $reflection->getMethod('methodWithManyParams');
        $parameters = $method->getParameters();

        $arguments = [
            'param1' => 'value1',
            'param2' => 101,
            'param3' => true,
            'variadic' => 'this-is-not-an-array',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Variadic argument for parameter \$variadic must be an array/');

        Parameters::resolveParameters($parameters, $arguments);
    }
}
