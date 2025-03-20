<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mock classes
foreach (glob(__DIR__ . '/mocks/*.php') as $f) {
    require_once $f;
}
