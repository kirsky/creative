<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * Where a task is in its life. The values are the codes the API sends and the database stores.
 * Which status may follow which is TaskWorkflow's business, not this enum's.
 */
enum TaskStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case UnderReview = 'under_review';
    case RevisionsRequired = 'revisions_required';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Completed and Cancelled: nothing leaves them.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
