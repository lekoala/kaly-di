<?php

declare(strict_types=1);

namespace Kaly\Di;

use InvalidArgumentException;
use Psr\Container\NotFoundExceptionInterface;

final class ReferenceNotFoundException extends InvalidArgumentException implements NotFoundExceptionInterface
{
    //empty
}
