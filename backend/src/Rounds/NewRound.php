<?php

declare(strict_types=1);

namespace App\Rounds;

/**
 * What a designer hands in for a round, validated: a note (null when none) and 1 to 25 distinct file ids.
 */
final readonly class NewRound
{
    /**
     * @param list<int> $fileIds
     */
    public function __construct(
        public ?string $note,
        public array $fileIds,
    ) {}
}
