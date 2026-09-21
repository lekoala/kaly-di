<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use PHPUnit\Framework\TestCase;

class DefinitionsLockTest extends TestCase
{
    public function testAllMutatorsThrowAfterLock(): void
    {
        $mutators = [
            'set' => fn(Definitions $d) => $d->set('service', TestObject::class),
            'bind' => fn(Definitions $d) => $d->bind(TestInterface::class, TestObject::class),
            'parameter' => fn(Definitions $d) => $d->parameter('service', 'value', 1),
            'parameters' => fn(Definitions $d) => $d->parameters('service', value: 1),
            'callback' => fn(Definitions $d) => $d->callback('service', fn() => null),
            'merge' => fn(Definitions $d) => $d->merge(new Definitions()),
            'rebind' => fn(Definitions $d) => $d->rebind('service', TestObject::class),
            'alias' => fn(Definitions $d) => $d->alias('alias', 'service'),
        ];

        foreach ($mutators as $name => $mutator) {
            $definitions = Definitions::create()->set('service', TestObject::class)->lock();
            try {
                $mutator($definitions);
                $this->fail("{$name} must reject locked definitions");
            } catch (DefinitionException $e) {
                $this->assertStringContainsString('locked', $e->getMessage(), $name);
            }
        }
    }

    public function testMutatorsThrowAfterLockWithAssertionsDisabled(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'zend.assertions=-1', __DIR__ . '/fixtures/lock_assertions_disabled.php'],
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
}
