<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * What a seed made, for the command to report: counts only.
 */
final readonly class SeedSummary
{
    public function __construct(
        public int $tasks,
        public int $accountsCreated,
        public int $files,
    ) {}
}
