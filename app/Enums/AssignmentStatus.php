<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
