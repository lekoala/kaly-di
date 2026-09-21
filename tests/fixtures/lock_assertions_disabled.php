<?php

declare(strict_types=1);

use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;

require dirname(__DIR__) . '/_bootstrap.php';

if (ini_get('zend.assertions') !== '-1') {
    fwrite(STDERR, "Assertions must be disabled\n");
    exit(2);
}

$mutators = [
    'set' => fn(Definitions $d) => $d->set('service', TestObject::class),
    'bind' => fn(Definitions $d) => $d->bind(TestInterface::class, TestObject::class),
    'parameter' => fn(Definitions $d) => $d->parameter('service', 'value', 1),
    'parameters' => fn(Definitions $d) => $d->parameters('service', value: 1),
    'callback' => fn(Definitions $d) => $d->callback('service', fn() => null),
    'merge' => fn(Definitions $d) => $d->merge(new Definitions()),
    'rebind' => fn(Definitions $d) => $d->rebind('service', TestObject::class),
];

foreach ($mutators as $name => $mutator) {
    $definitions = Definitions::create()->set('service', TestObject::class)->lock();
    try {
        $mutator($definitions);
    } catch (LogicException $e) {
        if (str_contains($e->getMessage(), 'locked')) {
            continue;
        }
        fwrite(STDERR, "{$name}: {$e->getMessage()}\n");
        exit(1);
    }
    fwrite(STDERR, "{$name} must reject locked definitions\n");
    exit(1);
}

echo "ok\n";
