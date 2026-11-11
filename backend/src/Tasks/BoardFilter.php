<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * What the board is narrowed by. Every field is optional and they combine. $q is a substring of the
 * offer, compared without regard to case (Cyrillic included); LIKE wildcards in it are literal.
 * Cancelled tasks are left out unless $includeCancelled.
 */
final readonly class BoardFilter
{
    public function __construct(
        public ?string $priority = null,
        public ?string $creativeType = null,
        public ?int $designerId = null,
        public ?string $q = null,
        public bool $includeCancelled = false,
    ) {}
}
