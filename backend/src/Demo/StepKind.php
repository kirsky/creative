<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * The things that happen to a demo task, each one a call of a real service.
 */
enum StepKind
{
    case Create;
    case Edit;
    case PickUp;
    case Release;
    case Submit;
    case RequestRevisions;
    case Approve;
    case Cancel;
}
