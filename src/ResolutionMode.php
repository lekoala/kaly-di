<?php

declare(strict_types=1);

namespace Kaly\Di;

enum ResolutionMode
{
    case Lenient;
    case Strict;
}
