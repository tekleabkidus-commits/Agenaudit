<?php

namespace App\Enums;

enum CreditEntryType: string
{
    case Issue = 'issue';
    case Repayment = 'repayment';
    case Adjustment = 'adjustment';
}
