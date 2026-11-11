<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;

/**
 * The only source of "now" in the application, so that time-dependent rules can be tested.
 * Implementations always return UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
