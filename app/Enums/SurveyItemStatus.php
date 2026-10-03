<?php

namespace App\Enums;

enum SurveyItemStatus: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Returned = 'returned';
}
