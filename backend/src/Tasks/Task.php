<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;

/**
 * The part of a task that decides who may do what. A plain value, no storage.
 *
 * $assigneeId is the current or, once the work is over, the final assignee. It is cleared only when
 * the designer releases the task, so it is null while the task is New and also on a task that was
 * cancelled before anyone picked it up.
 */
final readonly class Task
{
    public function __construct(
        public int $id,
        public int $createdBy,
        public ?int $assigneeId,
        public TaskStatus $status,
        public DateTimeImmutable $deadlineAt,
    ) {}
}
