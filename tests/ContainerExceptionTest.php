<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\ContainerException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Exception;

class ContainerExceptionTest extends TestCase
{
    public function testItCanBeInstantiated(): void
    {
        $exception = new ContainerException('test message', 123);
        $this->assertInstanceOf(ContainerException::class, $exception);
        $this->assertSame('test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
    }

    public function testItImplementsContainerExceptionInterface(): void
    {
        $exception = new ContainerException();
        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    public function testItExtendsException(): void
    {
        $exception = new ContainerException();
        $this->assertInstanceOf(Exception::class, $exception);
    }
}
