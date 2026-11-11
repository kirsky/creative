<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * How urgent a task is. The values are the codes the API sends and the database stores; the
 * frontend translates them. The order is the order the options are listed in, lowest first.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
