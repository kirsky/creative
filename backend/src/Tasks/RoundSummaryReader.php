<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * Where the round numbers of a card come from. Rounds and AI usage do not exist until the rounds
 * module does; NoRounds answers for every task until it replaces this binding in config/container.php.
 */
interface RoundSummaryReader
{
    /**
     * @param list<int> $taskIds
     * @return array<int, RoundSummary> by task id; a task missing from the result has no rounds
     */
    public function summaries(array $taskIds): array;
}
