<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Di\UnresolvableParameterException;
use PHPUnit\Framework\TestCase;

/**
 * Composition from the configuration guide: object autowiring together with
 * scalar parameters and an explicit test override. No framework dependency
 * and no environment mutation involved.
 */
class CompositionTest extends TestCase
{
    public function testGuideCompositionAutowiresObjectsAndConfiguresScalars(): void
    {
        $definitions = Definitions::create()
            ->bind(CompositionRendererInterface::class, CompositionNativeRenderer::class)
            ->bind(CompositionTranslatorInterface::class, CompositionTranslator::class)
            ->parameters(CompositionReportComposer::class, from: 'reports@example.test', defaultLocale: 'en');

        $container = $definitions->createContainer();
        $composer = $container->get(CompositionReportComposer::class);

        $this->assertSame('reports@example.test', $composer->from);
        $this->assertSame('en', $composer->defaultLocale);
        $this->assertInstanceOf(CompositionNativeRenderer::class, $composer->renderer);
        $this->assertInstanceOf(CompositionTranslator::class, $composer->translator);
        $this->assertSame($composer, $container->get(CompositionReportComposer::class));
    }

    public function testTestOverrideRebindsWithoutChangingTheClass(): void
    {
        $definitions = Definitions::create()
            ->bind(CompositionRendererInterface::class, CompositionNativeRenderer::class)
            ->bind(CompositionTranslatorInterface::class, CompositionTranslator::class)
            ->parameters(CompositionReportComposer::class, from: 'reports@example.test', defaultLocale: 'en')
            ->rebind(CompositionTranslatorInterface::class, CompositionFakeTranslator::class)
            ->parameters(CompositionReportComposer::class, from: 'test@example.test', defaultLocale: 'fr');

        $container = $definitions->createContainer();
        $composer = $container->get(CompositionReportComposer::class);

        $this->assertSame('test@example.test', $composer->from);
        $this->assertSame('fr', $composer->defaultLocale);
        $this->assertInstanceOf(CompositionFakeTranslator::class, $composer->translator);
    }

    public function testMakeCreatesDistinctRootsWithExplicitContextsAndSharedDependency(): void
    {
        $container = Definitions::create()
            ->bind(CompositionRendererInterface::class, CompositionNativeRenderer::class)
            ->createContainer();
        $injector = new Injector($container);

        $first = $injector->make(CompositionRequestHandler::class, requestId: 'req-1');
        $second = $injector->make(CompositionRequestHandler::class, requestId: 'req-2');

        $this->assertNotSame($first, $second);
        $this->assertSame('req-1', $first->requestId);
        $this->assertSame('req-2', $second->requestId);
        // Object dependencies still come from the shared container.
        $this->assertSame($container->get(CompositionNativeRenderer::class), $first->renderer);
        $this->assertSame($first->renderer, $second->renderer);
    }

    public function testDefinitionsParametersAreNotAppliedToTheMakeRoot(): void
    {
        $container = Definitions::create()
            ->bind(CompositionRendererInterface::class, CompositionNativeRenderer::class)
            ->bind(CompositionTranslatorInterface::class, CompositionTranslator::class)
            ->parameters(CompositionReportComposer::class, from: 'configured', defaultLocale: 'en')
            ->createContainer();
        $injector = new Injector($container);

        // Both object dependencies are resolvable: the failure must happen on
        // the scalar, proving Definitions parameters are ignored for the root.
        try {
            $injector->make(CompositionReportComposer::class);
            $this->fail('Expected an UnresolvableParameterException');
        } catch (UnresolvableParameterException $e) {
            $this->assertSame('from', $e->getParameterName());
        }
    }
}

interface CompositionRendererInterface
{
    public function render(string $template): string;
}

interface CompositionTranslatorInterface
{
    public function translate(string $key): string;
}

final class CompositionNativeRenderer implements CompositionRendererInterface
{
    public function render(string $template): string
    {
        return $template;
    }
}

final class CompositionTranslator implements CompositionTranslatorInterface
{
    public function translate(string $key): string
    {
        return $key;
    }
}

final class CompositionFakeTranslator implements CompositionTranslatorInterface
{
    public function translate(string $key): string
    {
        return 'fake:' . $key;
    }
}

final class CompositionReportComposer
{
    public function __construct(
        public readonly CompositionRendererInterface $renderer,
        public readonly CompositionTranslatorInterface $translator,
        public readonly string $from,
        public readonly string $defaultLocale,
    ) {}
}

final class CompositionRequestHandler
{
    public function __construct(
        public readonly CompositionNativeRenderer $renderer,
        public readonly string $requestId,
    ) {}
}
