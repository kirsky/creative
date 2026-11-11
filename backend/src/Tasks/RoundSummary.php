<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * What a task's review rounds add up to, for the card: how many rounds there were, the AI credits
 * spent on them (a decimal string, never a float) and whether the task is back with the designer
 * because the latest round was sent back for revisions.
 */
final readonly class RoundSummary
{
    public function __construct(
        public int $roundCount,
        public string $creditsTotal,
        public bool $isReturning,
    ) {}

    public static function none(): self
    {
        return new self(0, '0.00', false);
    }
}
