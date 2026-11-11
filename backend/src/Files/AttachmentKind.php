<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The role of a file on a task: part of the brief the buyer wrote, or something the designer delivered.
 */
enum AttachmentKind: string
{
    case Reference = 'reference';
    case Deliverable = 'deliverable';
}
