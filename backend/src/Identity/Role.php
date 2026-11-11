<?php

declare(strict_types=1);

namespace App\Identity;

enum Role: string
{
    case Buyer = 'buyer';
    case Designer = 'designer';
    case Manager = 'manager';
}
