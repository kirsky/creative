<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Files\AttachedTaskResolver;
use App\Tasks\Task;

/**
 * Stands in for the real resolver (which needs the tasks table): the test says which tasks a file is attached to.
 */
final class FakeAttachedTaskResolver implements AttachedTaskResolver
{
    /** @var array<int, list<Task>> */
    private array $tasks = [];

    public function attach(int $fileId, Task $task): void
    {
        $this->tasks[$fileId][] = $task;
    }

    public function tasksFor(int $fileId): array
    {
        return $this->tasks[$fileId] ?? [];
    }
}
