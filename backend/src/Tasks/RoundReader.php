<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * Where the rounds of a task come from, for the board and the task page. The rounds module
 * implements it; Tasks does not know how rounds are stored.
 */
interface RoundReader
{
    /**
     * What the rounds of several tasks add up to, in one query for all of them (the board must not
     * ask once per card).
     *
     * @param list<int> $taskIds
     * @return array<int, RoundSummary> by task id; a task missing from the result has no rounds
     */
    public function summaries(array $taskIds): array;

    /**
     * The rounds of one task, oldest first, with their files.
     *
     * @return list<RoundView>
     */
    public function forTask(int $taskId): array;
}
