<?php

namespace App\Models;

use App\Enums\ExternalVerificationStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;
    protected $fillable = [
        'reference','agent_id','brand_id','employee_id','type','status','amount','agent_balance_before','agent_balance_after','valid_payment_total','bank_payment_total','difference',
        'outstanding_credit_at_time','agent_system_at','agent_system_reference','risk_level','external_verification_status','reason','withdrawal_reason_code','withdrawal_reason_note','review_reason',
        'rejection_code','rejection_reason','completed_at','rejected_at',
    ];
    protected function casts(): array
    {
        return [
            'type'=>TransactionType::class,'status'=>TransactionStatus::class,'risk_level'=>RiskLevel::class,
            'external_verification_status'=>ExternalVerificationStatus::class,'amount'=>'decimal:2','agent_balance_before'=>'decimal:2','agent_balance_after'=>'decimal:2','valid_payment_total'=>'decimal:2',
            'bank_payment_total'=>'decimal:2','difference'=>'decimal:2','outstanding_credit_at_time'=>'decimal:2',
            'agent_system_at'=>'datetime','completed_at'=>'datetime','rejected_at'=>'datetime',
        ];
    }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function employee(): BelongsTo { return $this->belongsTo(User::class, 'employee_id'); }
    public function evidenceFiles(): HasMany { return $this->hasMany(EvidenceFile::class); }
    public function payments(): HasMany { return $this->hasMany(PaymentRecord::class); }
    public function confirmations(): HasMany { return $this->hasMany(TransactionConfirmation::class); }
    public function events(): HasMany { return $this->hasMany(TransactionEvent::class); }
    public function correctionRequests(): HasMany { return $this->hasMany(CorrectionRequest::class); }
    public function issuedCreditRecord(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(CreditRecord::class, 'issue_transaction_id'); }
    public function creditRepaymentAllocations(): HasMany { return $this->hasMany(CreditRepaymentAllocation::class, 'repayment_transaction_id'); }
}
