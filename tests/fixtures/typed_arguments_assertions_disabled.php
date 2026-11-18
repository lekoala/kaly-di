<?php

declare(strict_types=1);

use Kaly\Di\Container;
use Kaly\Di\ContainerException;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Tests\Mocks\TestBooleanFlag;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = [];

$container = Definitions::create()->parameter(TestBooleanFlag::class, 'enabled', 'false')->createContainer();
try {
    $container->get(TestBooleanFlag::class);
    $failures[] = 'Container::get() accepted a string for a bool parameter';
} catch (ContainerException $e) {
    if (!$e->getPrevious() instanceof InvalidArgumentException) {
        $failures[] = 'Container::get() did not keep the InvalidArgumentException as previous';
    }
}

$injector = new Injector(new Container());
try {
    $injector->invoke(static fn(bool $enabled): bool => $enabled, 'false');
    $failures[] = 'Injector::invoke() accepted a string for a bool parameter';
} catch (InvalidArgumentException) {
    // expected
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "ok\n";
