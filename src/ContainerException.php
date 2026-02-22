<?php

declare(strict_types=1);

namespace Kaly\Di;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends Exception implements ContainerExceptionInterface
{
    // empty
}
