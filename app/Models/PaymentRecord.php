<?php

namespace App\Models;

use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRecord extends Model
{
    protected $fillable = [
        'transaction_id','evidence_file_id','from_bank_id','to_bank_id','receiving_account_id','from_bank_raw','to_bank_raw','sender_account','sender_name',
        'receiver_account','receiver_name','amount','transaction_id_raw','normalized_transaction_id','duplicate_of_payment_id','transaction_at','account_match_method','account_match_confidence',
        'internal_status','external_status','external_response','external_request_keys','external_checked_at','risk_level','time_difference_minutes','rejection_code','rejection_reason',
    ];
    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2','transaction_at'=>'datetime','account_match_confidence'=>'float','internal_status'=>PaymentValidationStatus::class,
            'external_status'=>ExternalVerificationStatus::class,'external_response'=>'array','external_request_keys'=>'array','external_checked_at'=>'datetime',
            'risk_level'=>RiskLevel::class,'time_difference_minutes'=>'integer',
        ];
    }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function evidenceFile(): BelongsTo { return $this->belongsTo(EvidenceFile::class); }
    public function fromBank(): BelongsTo { return $this->belongsTo(Bank::class, 'from_bank_id'); }
    public function toBank(): BelongsTo { return $this->belongsTo(Bank::class, 'to_bank_id'); }
    public function receivingAccount(): BelongsTo { return $this->belongsTo(ReceivingAccount::class); }
    public function duplicateOf(): BelongsTo { return $this->belongsTo(PaymentRecord::class, 'duplicate_of_payment_id'); }
}
