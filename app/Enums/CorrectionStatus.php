<?php

namespace App\Enums;

enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsNewScreenshot = 'needs_new_screenshot';
}
