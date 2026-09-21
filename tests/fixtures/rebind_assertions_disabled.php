<?php

declare(strict_types=1);

use Kaly\Di\Definitions;

require dirname(__DIR__) . '/_bootstrap.php';

if (ini_get('zend.assertions') !== '-1') {
    throw new RuntimeException('Assertions must be disabled');
}

$cases = [
    'string' => 'AutoloadProbe\\Replacement',
    'object' => new stdClass(),
    'closure' => static fn() => new stdClass(),
];

foreach ($cases as $label => $replacement) {
    $id = 'AutoloadProbe\\Service_' . $label;
    $definitions = Definitions::create()->set($id, new stdClass());

    $attempts = [];
    $spy = static function (string $class) use (&$attempts): void {
        $attempts[] = $class;
    };

    // Prepend the spy so it observes even classes Composer could load.
    spl_autoload_register($spy, true, true);

    try {
        $definitions->rebind($id, $replacement);
    } finally {
        spl_autoload_unregister($spy);
    }

    if ($attempts !== []) {
        throw new RuntimeException("{$label} triggered autoload: " . implode(', ', $attempts));
    }
}

echo "ok\n";
