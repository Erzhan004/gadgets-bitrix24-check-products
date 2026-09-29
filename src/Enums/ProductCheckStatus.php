<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductCheckStatus: string
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case NotAttached = 'not_attached';
}
