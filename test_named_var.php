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
        public function methodWithManyParams(
            string $param1,
            int $param2,
            bool $param3,
            array $param4 = [],
            int $param5 = 0,
            bool $param6 = false,
            ?string $param7 = null,
            string ...$variadic
        ): void {}
    }

    $refl = new ReflectionMethod('Test', 'methodWithManyParams');
    $parameters = $refl->getParameters();

    $arguments = [
        'param1' => 'value1',
        'param2' => 101,
        'variadic' => ['var_a', 'var_b', 'var_c']
    ];

    $resolved = Kaly\Di\Parameters::resolveParameters($parameters, $arguments);
    echo "Resolved: " . json_encode($resolved) . "\n";

    $flattened = Kaly\Di\Parameters::flattenArguments($parameters, $resolved);
    echo "Flattened: " . json_encode($flattened) . "\n";
}
