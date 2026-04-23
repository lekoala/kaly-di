<?php

declare(strict_types=1);

namespace Kaly\Di;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class UnresolvableParameterException extends Exception implements ContainerExceptionInterface
{
    private ?string $parameterName = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $parameterName = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->parameterName = $parameterName;
    }

    public function getParameterName(): ?string
    {
        return $this->parameterName;
    }
}
