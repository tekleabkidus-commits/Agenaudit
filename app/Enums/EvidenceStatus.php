<?php

namespace App\Enums;

enum EvidenceStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Extracted = 'extracted';
    case NeedsReupload = 'needs_reupload';
    case PendingAdminExtraction = 'pending_admin_extraction';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
