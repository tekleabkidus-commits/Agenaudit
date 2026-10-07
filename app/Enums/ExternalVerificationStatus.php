<?php

namespace App\Enums;

enum ExternalVerificationStatus: string
{
    case NotRequired = 'not_required';
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Unavailable = 'unavailable';
}
