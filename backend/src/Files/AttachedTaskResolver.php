<?php

declare(strict_types=1);

namespace App\Files;

use App\Tasks\Task;

/**
 * Which tasks a file is attached to. FileAccess asks it, so that the files of a task are readable by
 * everybody who may view the task, without the Files module knowing how tasks are stored.
 */
interface AttachedTaskResolver
{
    /**
     * @return list<Task> the tasks the file is attached to (one at most today, since a file is attached once)
     */
    public function tasksFor(int $fileId): array;
}
