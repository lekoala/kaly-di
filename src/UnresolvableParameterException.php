<?php

declare(strict_types=1);

namespace Kaly\Di;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class UnresolvableParameterException extends Exception implements ContainerExceptionInterface
{
    // empty
}
