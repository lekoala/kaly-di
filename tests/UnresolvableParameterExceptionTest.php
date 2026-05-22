<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\UnresolvableParameterException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Exception;

class UnresolvableParameterExceptionTest extends TestCase
{
    public function testItCanBeInstantiated(): void
    {
        $exception = new UnresolvableParameterException('test message', 123);
        $this->assertInstanceOf(UnresolvableParameterException::class, $exception);
        $this->assertSame('test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
    }

    public function testItImplementsContainerExceptionInterface(): void
    {
        $exception = new UnresolvableParameterException();
        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    public function testItExtendsException(): void
    {
        $exception = new UnresolvableParameterException();
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testItCanStoreAndRetrieveParameterName(): void
    {
        $exception = new UnresolvableParameterException('msg', 0, null, 'myParam');
        $this->assertSame('myParam', $exception->getParameterName());

        $exceptionNoParam = new UnresolvableParameterException('msg');
        $this->assertNull($exceptionNoParam->getParameterName());
    }
}
