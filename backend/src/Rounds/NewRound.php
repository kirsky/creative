<?php

declare(strict_types=1);

namespace App\Rounds;

use App\AiUsage\UsageLine;

/**
 * What a designer hands in for a round, validated: a note (null when none), 1 to 25 distinct file ids and the AI usage lines
 * (none when no AI was used).
 */
final readonly class NewRound
{
    /**
     * @param list<int> $fileIds
     * @param list<UsageLine> $aiUsage
     */
    public function __construct(
        public ?string $note,
        public array $fileIds,
        public array $aiUsage = [],
    ) {}
}
