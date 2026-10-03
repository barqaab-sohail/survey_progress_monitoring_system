<?php

namespace App\Enums;

enum SurveyEntryStatus: string
{
    case Submitted = 'submitted';
    case PartiallyVerified = 'partially_verified';
    case Verified = 'verified';
    case Returned = 'returned';
}
