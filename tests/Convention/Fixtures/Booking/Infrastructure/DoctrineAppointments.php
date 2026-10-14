<?php

declare(strict_types=1);

namespace Kaly\Tests\Convention\Fixtures\Booking\Infrastructure;

use Kaly\Tests\Convention\Fixtures\Booking\Domain\Appointments;

final class DoctrineAppointments implements Appointments
{
    public function __construct(
        public string $tag = 'doctrine',
    ) {}
}
