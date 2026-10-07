<?php

namespace App\Enums;

enum PaymentValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Review = 'review';
    case Rejected = 'rejected';
}
