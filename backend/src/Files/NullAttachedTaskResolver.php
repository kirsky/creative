<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The resolver until tasks exist: no file is attached to any task, so only the uploader can read it.
 * The task repository replaces it in config/container.php.
 */
final class NullAttachedTaskResolver implements AttachedTaskResolver
{
    public function tasksFor(int $fileId): array
    {
        return [];
    }
}
