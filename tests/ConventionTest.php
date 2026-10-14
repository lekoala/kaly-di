<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\ContainerException;
use Kaly\Di\DefinitionException;
use Kaly\Di\Definitions;
use Kaly\Di\ReferenceNotFoundException;
use Kaly\Di\UnresolvableParameterException;
use Kaly\Tests\Convention\Fixtures\Booking\Domain\Ambig;
use Kaly\Tests\Convention\Fixtures\Booking\Domain\Appointments;
use Kaly\Tests\Convention\Fixtures\Booking\Domain\ConcreteService;
use Kaly\Tests\Convention\Fixtures\Booking\Infrastructure\DoctrineAppointments;
use Kaly\Tests\Convention\Fixtures\Booking\Infrastructure\InMemoryAppointments;
use Kaly\Tests\Convention\Fixtures\Broken\Domain\Missing;
use Kaly\Tests\Convention\Fixtures\Broken\Domain\NeedsMissing;
use Kaly\Tests\Convention\Fixtures\Catalog\Domain\Products;
use Kaly\Tests\Convention\Fixtures\Catalog\Infrastructure\DoctrineProducts;
use Kaly\Tests\Convention\Fixtures\Needs\Domain\Service;
use Kaly\Tests\Convention\Fixtures\Review\Domain\Répertoire;
use Kaly\Tests\Convention\Fixtures\Review\Infrastructure\DoctrineRépertoire;
use Kaly\Tests\Convention\Fixtures\Wrong\Domain\Thing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConventionTest extends TestCase
{
    private const FROM = 'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Domain\\{name}';

    private const TO = 'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Infrastructure\\Doctrine{name}';

    private function definitions(): Definitions
    {
        return Definitions::create()->bindConvention(self::FROM, self::TO);
    }

    public function testResolvesAcrossModulesWithOneRule(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertInstanceOf(DoctrineAppointments::class, $container->get(Appointments::class));
        $this->assertInstanceOf(DoctrineProducts::class, $container->get(Products::class));
    }

    public function testExplicitBindWinsOverConventionWithoutConflict(): void
    {
        $container = Definitions::create()
            ->bindConvention(self::FROM, self::TO)
            ->bind(Appointments::class, InMemoryAppointments::class)
            ->createContainer();

        $this->assertInstanceOf(InMemoryAppointments::class, $container->get(Appointments::class));
        // Untouched sibling still resolves by convention.
        $this->assertInstanceOf(DoctrineProducts::class, $container->get(Products::class));
    }

    public function testHasIsTrueForMissingCandidateButGetFailsExplicitly(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertTrue($container->has(Missing::class));

        try {
            $container->get(Missing::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            $this->assertNotInstanceOf(ReferenceNotFoundException::class, $e);
            $this->assertStringContainsString('DoctrineMissing', $e->getMessage());
            $this->assertStringContainsString(self::FROM, $e->getMessage());
        }
    }

    public function testNullableDependencyDoesNotMaskAMissingImplementation(): void
    {
        $container = $this->definitions()->createContainer();

        try {
            $container->get(NeedsMissing::class);
            $this->fail('Expected a ContainerException');
        } catch (ContainerException $e) {
            // The failure propagates instead of falling back to null, with
            // the convention that caused it named in the message.
            $this->assertStringContainsString('DoctrineMissing', $e->getMessage());
        }
    }

    public function testIncompatibleCandidateFails(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertTrue($container->has(Thing::class));
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('does not implement');
        $container->get(Thing::class);
    }

    public function testAmbiguityFailsAtResolutionNotAtRegistration(): void
    {
        $definitions = Definitions::create()
            ->bindConvention(self::FROM, self::TO)
            ->bindConvention(
                'Kaly\\Tests\\Convention\\Fixtures\\Booking\\Domain\\{name}',
                'Kaly\\Tests\\Convention\\Fixtures\\Booking\\Infrastructure\\Special{name}',
            );
        $container = $definitions->createContainer();

        // has() stays true so the ambiguity surfaces from get(), unwrapped.
        $this->assertTrue($container->has(Ambig::class));

        try {
            $container->get(Ambig::class);
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString(Ambig::class, $e->getMessage());
            $this->assertStringContainsString(self::FROM, $e->getMessage());
            $this->assertStringContainsString('Special{name}', $e->getMessage());
        }
    }

    public function testConcreteClassInDomainKeepsAutowiring(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertInstanceOf(ConcreteService::class, $container->get(ConcreteService::class));
    }

    public function testUnmatchedInterfaceStillMisses(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertFalse($container->has(Mocks\TestInterface::class));
        $this->expectException(ReferenceNotFoundException::class);
        $container->get(Mocks\TestInterface::class);
    }

    public function testLifecycleMatchesBind(): void
    {
        $container = $this->definitions()->createContainer();

        $byInterface = $container->get(Appointments::class);
        $byClass = $container->get(DoctrineAppointments::class);

        $this->assertInstanceOf(DoctrineAppointments::class, $byInterface);
        $this->assertNotSame($byInterface, $byClass);
        $this->assertSame($byInterface, $container->get(Appointments::class));
    }

    public function testParametersAndCallbacksApplyToConventionBuilds(): void
    {
        $container = Definitions::create()
            ->bindConvention(self::FROM, self::TO)
            ->parameters(Appointments::class, tag: 'custom')
            ->callback(Appointments::class, fn(DoctrineAppointments $instance): DoctrineAppointments => $instance)
            ->createContainer();

        $this->assertSame('custom', $container->get(Appointments::class)->tag);
    }

    public function testConstructorFailureKeepsTheResolutionPath(): void
    {
        $container = $this->definitions()->createContainer();

        try {
            $container->get(Service::class);
            $this->fail('Expected an UnresolvableParameterException');
        } catch (UnresolvableParameterException $e) {
            $this->assertSame('apiKey', $e->getParameterName());
            $this->assertStringContainsString('cannot resolve parameter: `apiKey`', $e->getMessage());
            $this->assertStringContainsString(Service::class . '::$apiKey', (string) $e->getResolutionPath());
        }
    }

    public function testDuplicateSourceTemplateIsRejected(): void
    {
        $definitions = $this->definitions();

        $this->expectException(DefinitionException::class);
        $definitions->bindConvention(self::FROM, self::TO);
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function invalidTemplates(): array
    {
        return [
            'missing {name}' => [
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Domain',
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Infrastructure\\Doctrine',
            ],
            'unknown placeholder' => [
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Domain\\{name}\\{other}',
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Infrastructure\\Doctrine{name}',
            ],
            'adjacent placeholders' => [
                'Kaly\\Tests\\Convention\\Fixtures\\{module}{name}',
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Infrastructure\\Doctrine{name}',
            ],
            'uncaptured target variable' => [
                'Kaly\\Tests\\Convention\\Fixtures\\Domain\\{name}',
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Infrastructure\\Doctrine{name}',
            ],
            'no namespace' => [
                '{name}',
                'Doctrine{name}',
            ],
            'trailing brace in source' => [
                self::FROM . '}',
                self::TO,
            ],
            'stray opening brace in source' => [
                'Kaly\\Tests\\Convention\\Fixtures\\{module}\\Domain\\{na',
                self::TO,
            ],
            'stray brace in target' => [
                self::FROM,
                self::TO . '{',
            ],
        ];
    }

    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplatesAreRejected(string $from, string $to): void
    {
        $this->expectException(DefinitionException::class);
        Definitions::create()->bindConvention($from, $to);
    }

    public function testMergeRejectsDuplicateConventionsAtomically(): void
    {
        $target = $this->definitions();
        $incoming = Definitions::create()->bindConvention(self::FROM, self::TO, source: 'incoming');

        try {
            $target->merge($incoming);
            $this->fail('Expected a DefinitionException');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString(self::FROM, $e->getMessage());
        }

        // Failed merge leaves the target untouched: still a single rule.
        $container = $target->createContainer();
        $this->assertInstanceOf(DoctrineAppointments::class, $container->get(Appointments::class));
    }

    public function testMergeAcceptsDisjointAndOverlappingConventions(): void
    {
        $target = $this->definitions();
        $target->merge(Definitions::create()->bindConvention(
            'Kaly\\Tests\\Convention\\Fixtures\\Booking\\Domain\\{name}',
            'Kaly\\Tests\\Convention\\Fixtures\\Booking\\Infrastructure\\Special{name}',
        ));

        // Overlapping rules coexist until both match the same interface.
        $container = $target->createContainer();
        $this->assertInstanceOf(DoctrineProducts::class, $container->get(Products::class));
        $this->expectException(DefinitionException::class);
        $container->get(Ambig::class);
    }

    public function testBindConventionIsLockedWithDefinitions(): void
    {
        $definitions = $this->definitions();
        $definitions->createContainer();

        $this->expectException(DefinitionException::class);
        $definitions->bindConvention(self::FROM, self::TO);
    }

    public function testConventionsAreSharedButInstancesArePerContainer(): void
    {
        $definitions = $this->definitions();

        $first = $definitions->createContainer();
        $second = $definitions->createContainer();

        $this->assertSame($first->get(Appointments::class), $first->get(Appointments::class));
        $this->assertNotSame($first->get(Appointments::class), $second->get(Appointments::class));

        // Resolution never materializes bindings into the locked definitions.
        $this->assertFalse($definitions->has(Appointments::class));
        $this->assertSame([], $definitions->getSources()[Appointments::class] ?? []);
    }

    public function testCapturesAcceptNonAsciiIdentifiers(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertTrue($container->has(Répertoire::class));
        $this->assertInstanceOf(DoctrineRépertoire::class, $container->get(Répertoire::class));
    }

    public function testIdsAreMatchedLikeClassNames(): void
    {
        $container = $this->definitions()->createContainer();

        $this->assertTrue($container->has('\\' . Appointments::class));
        $this->assertInstanceOf(DoctrineAppointments::class, $container->get('\\' . strtolower(Appointments::class)));
    }
}
