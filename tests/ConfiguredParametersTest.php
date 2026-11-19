<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Di\ContainerException;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Mocks\TestNestedLeaf;
use Kaly\Tests\Mocks\TestNestedMiddle;
use Kaly\Tests\Mocks\TestNestedRoot;
use Kaly\Tests\Mocks\TestObject5;
use Kaly\Tests\Mocks\TestQueueJob;
use Kaly\Tests\Mocks\TestQueueWorker;
use Kaly\Tests\Mocks\TestReferenceParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ConfiguredParametersTest extends TestCase
{
    public function testConfiguredParametersAfterAClosureAreResolved(): void
    {
        $container = Definitions::create()
            ->parameter(TestObject5::class, 'v', fn(): string => 'from closure')
            ->parameter(TestObject5::class, 'v2', 'after closure')
            ->parameter(TestObject5::class, 'arr', ['configured'])
            ->parameter(TestObject5::class, 'v3', fn(): string => 'last closure')
            ->createContainer();

        $instance = $container->get(TestObject5::class);

        $this->assertInstanceOf(TestObject5::class, $instance);
        $this->assertSame('from closure', $instance->v);
        $this->assertSame('after closure', $instance->v2);
        $this->assertSame(['configured'], $instance->arr);
        $this->assertSame('last closure', $instance->v3);
    }

    public function testConfiguredClosureFailurePreservesTheNestedResolutionPath(): void
    {
        $failure = null;
        $container = Definitions::create()
            ->parameter(TestNestedRoot::class, 'middle', function (ContainerInterface $c) use (&$failure): object {
                try {
                    return $c->get(TestNestedMiddle::class);
                } catch (UnresolvableParameterException $e) {
                    $failure = $e;
                    throw $e;
                }
            })
            ->createContainer();

        try {
            $container->get(TestNestedRoot::class);
            $this->fail('Expected a nested resolution failure');
        } catch (UnresolvableParameterException $e) {
            $path =
                TestNestedRoot::class
                . '::$middle -> '
                . TestNestedMiddle::class
                . '::$leaf -> '
                . TestNestedLeaf::class
                . '::$apiKey';
            $this->assertInstanceOf(UnresolvableParameterException::class, $failure);
            $this->assertSame($failure, $e->getPrevious());
            $this->assertSame(0, $e->getCode());
            $this->assertSame('middle', $e->getParameterName());
            $this->assertSame(TestNestedRoot::class, $e->getObjectId());
            $this->assertSame($path, $e->getResolutionPath());
            $this->assertStringContainsString('Path: ' . $path, $e->getMessage());
        }
    }

    /**
     * A definition may spell the concrete class with a leading backslash or a
     * different case. PHP still resolves the class, but configured parameters
     * are indexed by its declared name: losing it would silently fall back to
     * the constructor default.
     */
    public function testBindingKeepsTheConcreteClassConfiguration(): void
    {
        $definitions = Definitions::create()
            ->parameter(TestQueueWorker::class, 'queue', 'priority')
            ->bind(TestQueueJob::class, '\\' . TestQueueWorker::class);

        $this->assertSame('priority', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    public function testBindingKeepsTheConfigurationWithACaseVariant(): void
    {
        $definitions = Definitions::create()
            ->parameter(TestQueueWorker::class, 'queue', 'priority')
            ->bind(TestQueueJob::class, strtolower(TestQueueWorker::class));

        $this->assertSame('priority', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    public function testSetKeepsTheConcreteClassConfiguration(): void
    {
        $definitions = Definitions::create()->parameter(TestQueueWorker::class, 'queue', 'priority')->set(
            'worker',
            '\\' . TestQueueWorker::class,
        );

        $this->assertSame('priority', $definitions->createContainer()->get('worker')->queue);
    }

    public function testFactoryReturningAClassKeepsTheConcreteClassConfiguration(): void
    {
        $definitions = Definitions::create()->parameter(TestQueueWorker::class, 'queue', 'priority')->set(
            'worker',
            static fn(): string => '\\' . TestQueueWorker::class,
        );

        $this->assertSame('priority', $definitions->createContainer()->get('worker')->queue);
    }

    /**
     * A definition may target a PHP alias of the concrete class
     * (class_alias()). Reflection resolves it to the declared class, but
     * parameters configured under the alias spelling must still apply: they
     * worked before ids were canonicalized to the declared name, and dropping
     * them silently falls back to the constructor default.
     */
    public function testBindingKeepsTheConfigurationOfAnAliasedTarget(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerAlias');

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'TestQueueWorkerAlias')
            ->parameter('TestQueueWorkerAlias', 'queue', 'priority');

        $this->assertSame('priority', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    public function testSetKeepsTheConfigurationOfAnAliasedTarget(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerSet');

        $definitions = Definitions::create()->set('worker', 'TestQueueWorkerSet')->parameter(
            'TestQueueWorkerSet',
            'queue',
            'priority',
        );

        $this->assertSame('priority', $definitions->createContainer()->get('worker')->queue);
    }

    /**
     * PHP resolves the case of a short class name, but writable ids keep the
     * spelling as written: a definition may spell a global class differently
     * from its declared name.
     */
    public function testBindingKeepsTheConfigurationWithACaseVariantOfAGlobalName(): void
    {
        require_once __DIR__ . '/fixtures/GlobalQueueWorker.php';

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'globalqueueworker')
            ->parameter('globalqueueworker', 'queue', 'priority');

        $this->assertSame('priority', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    /**
     * The declared class name keeps its own configuration when the target
     * spells the class differently and carries no configuration of its own.
     */
    public function testBindingKeepsTheDeclaredClassConfigurationWithAnAliasedTarget(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerNoConfig');

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'TestQueueWorkerNoConfig')
            ->parameter(TestQueueWorker::class, 'queue', 'priority');

        $this->assertSame('priority', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    /**
     * The requested id still wins over a configuration keyed by the target
     * spelling.
     */
    public function testServiceIdConfigurationWinsOverTheTargetSpelling(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerOverride');

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'TestQueueWorkerOverride')
            ->parameter('TestQueueWorkerOverride', 'queue', 'from-target')
            ->parameter(TestQueueJob::class, 'queue', 'from-id');

        $this->assertSame('from-id', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    /**
     * The target spelling wins over the declared class name: it is what the
     * binding explicitly names for this service.
     */
    public function testTargetSpellingConfigurationWinsOverTheDeclaredClassName(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerSpecific');

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'TestQueueWorkerSpecific')
            ->parameter(TestQueueWorker::class, 'queue', 'from-declared')
            ->parameter('TestQueueWorkerSpecific', 'queue', 'from-target');

        $this->assertSame('from-target', $definitions->createContainer()->get(TestQueueJob::class)->queue);
    }

    public function testUnknownParametersReportTheTargetSpelling(): void
    {
        class_alias(TestQueueWorker::class, 'TestQueueWorkerTypo');

        $definitions = Definitions::create()
            ->bind(TestQueueJob::class, 'TestQueueWorkerTypo')
            ->parameter('TestQueueWorkerTypo', 'queue', 'valid')
            ->parameter('TestQueueWorkerTypo', 'queeu', 'invalid');
        $container = $definitions->createContainer();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            'Unknown configured parameter(s) for `Kaly\Tests\Mocks\TestQueueWorker` '
            . '(id `Kaly\Tests\Mocks\TestQueueJob`, target `TestQueueWorkerTypo`): `queeu`. Available: `queue`.',
        );
        $container->get(TestQueueJob::class);
    }

    public function testUnknownBindingParametersAreRejectedBeforeClosuresRun(): void
    {
        $container = Definitions::create()
            ->bind(TestQueueJob::class, TestQueueWorker::class, [
                'queue' => fn() => $this->fail('The parameter closure must not run'),
                'queeu' => 'invalid',
            ])
            ->createContainer();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Unknown configured parameter(s)');
        $container->get(TestQueueJob::class);
    }

    /**
     * Characterization of the Container path: the signature checks run while
     * the argument list is built, after the configured closures. A
     * by-reference constructor is rejected (like Injector), but its configured
     * closure has already executed, and the caller receives a
     * ContainerException whose previous is the InvalidArgumentException.
     */
    public function testContainerRejectsAByReferenceConstructorAfterConfiguredClosuresRun(): void
    {
        $ran = false;
        $container = Definitions::create()
            ->parameter(TestReferenceParameter::class, 'value', function (ContainerInterface $c) use (&$ran): int {
                $ran = true;
                return 42;
            })
            ->createContainer();

        try {
            $container->get(TestReferenceParameter::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertTrue($ran, 'The configured closure must have run before the signature check');
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            $this->assertStringContainsString('by reference', $e->getPrevious()->getMessage());
            $this->assertStringContainsString('$value', $e->getPrevious()->getMessage());
        }
    }

    /** @return array<string,array{string}> */
    public static function configuredServiceIds(): array
    {
        return ['class id' => [TestObject5::class], 'custom id' => ['configured']];
    }

    #[DataProvider('configuredServiceIds')]
    public function testUnknownParametersReportSortedNamesAndTheTarget(string $id): void
    {
        $definitions = Definitions::create()
            ->set($id, TestObject5::class)
            ->parameter($id, 'v', 'valid')
            ->parameter($id, 'zTypo', 'invalid')
            ->parameter($id, 'aTypo', 'invalid');
        $container = $definitions->createContainer();
        $target = '`' . TestObject5::class . '`';
        if ($id !== TestObject5::class) {
            $target .= ' (id `configured`)';
        }

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            "Unknown configured parameter(s) for {$target}: `aTypo`, `zTypo`. Available: `v`, `v2`, `arr`, `v3`.",
        );
        $container->get($id);
    }
}
