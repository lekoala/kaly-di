<?php

namespace Psr\Container {
    if (!interface_exists('Psr\Container\ContainerInterface')) {
        interface ContainerInterface {
            public function get(string $id);
            public function has(string $id): bool;
        }
    }
    if (!interface_exists('Psr\Container\ContainerExceptionInterface')) {
        interface ContainerExceptionInterface {}
    }
    if (!interface_exists('Psr\Container\NotFoundExceptionInterface')) {
        interface NotFoundExceptionInterface extends ContainerExceptionInterface {}
    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    use Kaly\Di\Container;
    use Kaly\Di\ContainerException;
    use Kaly\Di\Definitions;

    function assertExceptionDoesNotLeak($callback, $context) {
        try {
            $callback();
            echo "FAILED: Expected exception for $context\n";
            exit(1);
        } catch (ContainerException $e) {
            if (strpos($e->getMessage(), '12345') !== false || strpos($e->getMessage(), 'with message') !== false) {
                echo "FAILED: Leak detected in $context message: " . $e->getMessage() . "\n";
                exit(1);
            }
            if (!$e->getPrevious()) {
                echo "FAILED: Previous exception missing for $context\n";
                exit(1);
            }
            echo "PASSED: $context\n";
        }
    }

    // Test 1: Exception during instantiation (build method)
    class ThrowingClass {
        public function __construct() {
             throw new \Exception("Secret 12345");
        }
    }
    $container = new Container();
    assertExceptionDoesNotLeak(fn() => $container->get(ThrowingClass::class), "instantiation (build)");

    // Test 2: InvalidArgumentException during resolution (resolveConstructorArguments method)
    class VariadicClass {
        public function __construct(string ...$args) {}
    }
    $definitions = new Definitions();
    $definitions->parameter(VariadicClass::class, 'args', 'not an array');
    $containerWithDefs = new Container($definitions);
    assertExceptionDoesNotLeak(fn() => $containerWithDefs->get(VariadicClass::class), "resolution (resolveConstructorArguments)");

    echo "All security verification tests PASSED\n";
}
