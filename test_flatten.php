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

    class MockParameter {
        private $name;
        private $position;
        private $isVariadic;
        private $defaultValue;
        private $hasDefault;

        public function __construct($name, $position, $isVariadic = false, $defaultValue = null, $hasDefault = false) {
            $this->name = $name;
            $this->position = $position;
            $this->isVariadic = $isVariadic;
            $this->defaultValue = $defaultValue;
            $this->hasDefault = $hasDefault;
        }

        public function getName() { return $this->name; }
        public function getPosition() { return $this->position; }
        public function isVariadic() { return $this->isVariadic; }
        public function getType() { return null; }
        public function isDefaultValueAvailable() { return $this->hasDefault; }
        public function getDefaultValue() { return $this->defaultValue; }
        public function allowsNull() { return true; }
    }

    $parameters = [
        new MockParameter('param1', 0),
        new MockParameter('param2', 1),
        new MockParameter('param3', 2),
        new MockParameter('param4', 3, false, [], true),
        new MockParameter('param5', 4, false, 0, true),
        new MockParameter('param6', 5, false, false, true),
        new MockParameter('param7', 6, false, null, true),
        new MockParameter('param8', 7, false, null, true),
        new MockParameter('variadic', 8, true)
    ];

    $arguments = ['test', 123, true];

    $resolved = Kaly\Di\Parameters::resolveParameters($parameters, $arguments);
    echo "Resolved: " . json_encode($resolved) . "\n";

    $flattened = Kaly\Di\Parameters::flattenArguments($parameters, $resolved);
    echo "Flattened: " . json_encode($flattened) . "\n";
}
