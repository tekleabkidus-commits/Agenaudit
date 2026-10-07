<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditRepaymentAllocation extends Model
{
    protected $fillable = ['credit_record_id','repayment_transaction_id','amount','allocated_at'];

    protected function casts(): array
    {
        return ['amount'=>'decimal:2','allocated_at'=>'datetime'];
    }

    public function creditRecord(): BelongsTo { return $this->belongsTo(CreditRecord::class); }
    public function repaymentTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'repayment_transaction_id'); }
}
