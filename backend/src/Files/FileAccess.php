<?php

declare(strict_types=1);

namespace App\Files;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\Action;
use App\Tasks\TaskPolicy;

/**
 * Who may put files on the server and who may read one.
 *
 * A file can be read by the user who uploaded it and by everybody who may view a task it is attached
 * to, so a designer who may see a task in the pool also sees its reference files, and a buyer sees
 * what the designer delivered. For anybody else the file does not exist: callers answer 404, never 403.
 */
final class FileAccess
{
    public function __construct(
        private readonly FileStore $files,
        private readonly AttachedTaskResolver $attachments,
        private readonly TaskPolicy $policy,
    ) {}

    /**
     * Buyers attach references and designers attach deliverables; a manager is read-only.
     */
    public function canUpload(User $user): bool
    {
        return $user->role !== Role::Manager;
    }

    public function canRead(User $user, int $fileId): bool
    {
        $file = $this->files->find($fileId);
        if ($file === null) {
            return false;
        }

        if ($file->uploaderId === $user->id) {
            return true;
        }

        foreach ($this->attachments->tasksFor($fileId) as $task) {
            if ($this->policy->can($user, Action::ViewTask, $task)) {
                return true;
            }
        }

        return false;
    }
}
