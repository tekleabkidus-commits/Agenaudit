<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentInput extends Model
{
    protected $fillable = [
        'transaction_id','employee_id','input_type','input_content','reference',
        'normalized_reference','status','failure_code','failure_reason',
        'payment_record_id','checked_at',
    ];

    protected function casts(): array
    {
        return ['input_content'=>'encrypted','checked_at'=>'datetime'];
    }

    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function employee(): BelongsTo { return $this->belongsTo(User::class); }
    public function paymentRecord(): BelongsTo { return $this->belongsTo(PaymentRecord::class,'payment_record_id'); }
}
