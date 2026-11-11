<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * A move between statuses, named for the act that causes it (spec section 4.2).
 * TaskWorkflow says where each one may start and where it ends.
 */
enum Transition
{
    case Pickup;
    case Submit;
    case RequestRevisions;
    case Approve;
    case Release;
    case Cancel;
}
