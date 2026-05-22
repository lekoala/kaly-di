<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Exception;
use Kaly\Di\CircularReferenceException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;

class CircularReferenceExceptionTest extends TestCase
{
    public function testItCanBeInstantiated(): void
    {
        $exception = new CircularReferenceException('circular ref', 0);
        $this->assertInstanceOf(CircularReferenceException::class, $exception);
        $this->assertSame('circular ref', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testItImplementsContainerExceptionInterface(): void
    {
        $exception = new CircularReferenceException();
        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    public function testItExtendsException(): void
    {
        $exception = new CircularReferenceException();
        $this->assertInstanceOf(Exception::class, $exception);
    }
}
