<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;

/**
 * A validated brief, ready to be stored. $geo has no duplicates, $deadline is UTC to the second.
 */
final readonly class NewTask
{
    /**
     * @param list<string> $geo
     * @param list<NewReference> $references
     */
    public function __construct(
        public string $offer,
        public array $geo,
        public CreativeType $creativeType,
        public int $quantity,
        public string $description,
        public Priority $priority,
        public DateTimeImmutable $deadline,
        public array $references,
    ) {}
}
