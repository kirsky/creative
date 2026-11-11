<?php

declare(strict_types=1);

namespace App\Demo;

use RuntimeException;

/**
 * Seeding was asked for while tasks exist already; the way forward is the reset.
 */
final class AlreadySeededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('already seeded; use demo:reset');
    }
}
