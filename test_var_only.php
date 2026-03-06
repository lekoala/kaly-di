<?php
namespace Psr\Container {
    interface ContainerInterface {
        public function get(string $id);
        public function has(string $id): bool;
    }
}
namespace Kaly\Di {
    class UnresolvableParameterException extends \Exception {
        private $parameterName;
        public function __construct($message, $code = 0, $previous = null, $parameterName = '') {
            parent::__construct($message, $code, $previous);
            $this->parameterName = $parameterName;
        }
    }
}
namespace {
    require 'src/Parameters.php';

    class Test {
        public function variadicOnly(...$variadic): void {}
    }

    $refl = new ReflectionMethod('Test', 'variadicOnly');
    $parameters = $refl->getParameters();

    $arguments = ['var1', 'var2'];

    $resolved = Kaly\Di\Parameters::resolveParameters($parameters, $arguments);
    echo "Resolved: " . json_encode($resolved) . "\n";

    $flattened = Kaly\Di\Parameters::flattenArguments($parameters, $resolved);
    echo "Flattened: " . json_encode($flattened) . "\n";
}
