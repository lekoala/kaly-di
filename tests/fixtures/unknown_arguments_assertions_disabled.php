<?php

declare(strict_types=1);

use Kaly\Di\Parameters;

require dirname(__DIR__) . '/_bootstrap.php';

if (ini_get('zend.assertions') !== '-1') {
    fwrite(STDERR, "Assertions must be disabled\n");
    exit(2);
}

$parameters = (new ReflectionFunction(fn(string $a = 'x', string $b = 'y'): string => $a . $b))->getParameters();

$cases = [
    'unknown named' => ['nope' => 1],
    'double assignment' => [0 => '1', 'a' => '2'],
    'surplus positional' => ['1', '2', '3'],
    'positional after named' => ['b' => '1', 0 => '2'],
];

foreach ($cases as $name => $arguments) {
    try {
        Parameters::resolveParameters($parameters, $arguments);
    } catch (InvalidArgumentException) {
        continue;
    }
    fwrite(STDERR, "{$name} must be rejected\n");
    exit(1);
}

echo "ok\n";
