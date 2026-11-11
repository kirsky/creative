<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * Something a user may or may not do, for TaskPolicy to answer (spec section 3).
 * Decide is both "approve" and "request revisions": the same people, in the same status.
 */
enum Action
{
    case CreateTask;
    case ViewTask;
    case EditBrief;
    case PickUp;
    case Release;
    case Submit;
    case ReportAiUsage;
    case Decide;
    case Cancel;
    case ViewDashboard;
}
