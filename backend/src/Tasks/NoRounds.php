<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * The reader while no task can have a round: zero rounds, "0.00" credits, not returning.
 */
final class NoRounds implements RoundSummaryReader
{
    public function summaries(array $taskIds): array
    {
        return [];
    }
}
