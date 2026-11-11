<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Files\AttachedTaskResolver;
use App\Shared\Db\Database;
use PDO;

/**
 * Which tasks a file is attached to, from file_attachments and tasks. FileAccess asks it, so that a
 * reference file can be read by whoever may view its task.
 */
final class AttachedTasks implements AttachedTaskResolver
{
    public function __construct(private readonly Database $database) {}

    public function tasksFor(int $fileId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT t.id, t.created_by, t.assignee_id, t.status, t.deadline_at
             FROM file_attachments a JOIN tasks t ON t.id = a.task_id
             WHERE a.file_id = ?',
        );
        $statement->execute([$fileId]);

        return array_values(array_map(TaskRepository::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }
}
