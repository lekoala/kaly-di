<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Di\ReferenceNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

class ReferenceNotFoundExceptionTest extends TestCase
{
    public function testItCanBeInstantiated(): void
    {
        $exception = new ReferenceNotFoundException('not found', 0);
        $this->assertInstanceOf(ReferenceNotFoundException::class, $exception);
        $this->assertSame('not found', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testItImplementsNotFoundExceptionInterface(): void
    {
        $exception = new ReferenceNotFoundException();
        $this->assertInstanceOf(NotFoundExceptionInterface::class, $exception);
    }

    public function testItExtendsInvalidArgumentException(): void
    {
        $exception = new ReferenceNotFoundException();
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
    }
}
