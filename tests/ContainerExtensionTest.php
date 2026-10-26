<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObject;
use PHPUnit\Framework\TestCase;

class ContainerExtensionTest extends TestCase
{
    public function testSubclassesCanExtendBuildingAndConfiguration(): void
    {
        $container = new class extends Container {
            /** @var list<string> */
            public array $events = [];

            protected function build(string $id): object
            {
                $this->events[] = 'build';
                return parent::build($id);
            }

            protected function configure(object $instance, string $id): void
            {
                $this->events[] = 'configure';
                parent::configure($instance, $id);
                if ($instance instanceof TestObject) {
                    $instance->setVal('configured by subclass');
                }
            }
        };

        $instance = $container->get(TestObject::class);

        $this->assertSame('configured by subclass', $instance->getVal());
        $this->assertSame($instance, $container->get(TestObject::class));
        $this->assertSame(['build', 'configure'], $container->events);
    }

    public function testNamedCallbacksOnTheClassAndServiceBothRun(): void
    {
        $events = [];
        $definitions = Definitions::create()
            ->set('service', TestObject::class)
            ->callback(
                TestObject::class,
                function () use (&$events): void {
                    $events[] = 'class';
                },
                'configure',
            )
            ->callback(
                'service',
                function () use (&$events): void {
                    $events[] = 'service';
                },
                'configure',
            );
        $container = $definitions->createContainer();

        $instance = $container->get('service');

        $this->assertSame($instance, $container->get('service'));
        $this->assertSame(['class', 'service'], $events);
    }
}
