<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditRecord extends Model
{
    protected $fillable = [
        'agent_id','issue_transaction_id','original_amount','repaid_amount','outstanding_amount',
        'status','issued_at','due_at','paid_at',
    ];

    protected function casts(): array
    {
        return [
            'original_amount'=>'decimal:2',
            'repaid_amount'=>'decimal:2',
            'outstanding_amount'=>'decimal:2',
            'issued_at'=>'datetime',
            'due_at'=>'datetime',
            'paid_at'=>'datetime',
        ];
    }

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function issueTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'issue_transaction_id'); }
    public function repaymentAllocations(): HasMany { return $this->hasMany(CreditRepaymentAllocation::class); }

    public function ageDays(): int
    {
        return max(0, $this->issued_at?->diffInDays(now()) ?? 0);
    }

    public function overdueDays(): int
    {
        if (!$this->due_at || $this->status === 'paid' || now()->lte($this->due_at)) return 0;
        return $this->due_at->diffInDays(now());
    }
}
