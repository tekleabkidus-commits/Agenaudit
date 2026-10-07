<?php

namespace App\Enums;

enum TransactionType: string
{
    case PaidTopup = 'paid_topup';
    case Credit = 'credit';
    case CreditRepayment = 'credit_repayment';
    case Withdrawal = 'withdrawal';
    case Commission = 'commission';

    public function label(): string
    {
        return match ($this) {
            self::PaidTopup => 'Add Balance (Paid)',
            self::Credit => 'Give Credit',
            self::CreditRepayment => 'Credit Repayment',
            self::Withdrawal => 'Remove Balance / Withdrawal',
            self::Commission => 'Commission Deposit',
        };
    }

    public function requiresAgentScreenshot(): bool
    {
        return $this !== self::CreditRepayment;
    }

    public function requiresBankEvidence(): bool
    {
        return in_array($this, [self::PaidTopup, self::CreditRepayment], true);
    }
}
