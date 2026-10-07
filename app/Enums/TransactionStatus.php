<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case NeedsClearerScreenshot = 'needs_clearer_screenshot';
    case PendingAdminExtraction = 'pending_admin_extraction';
    case PendingAdminReview = 'pending_admin_review';
    case PendingCorrectionApproval = 'pending_correction_approval';
    case ReadyForReview = 'ready_for_review';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
