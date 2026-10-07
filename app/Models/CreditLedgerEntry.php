<?php

namespace App\Models;

use App\Enums\CreditEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    protected $fillable = ['agent_id','transaction_id','entry_type','amount','balance_before','balance_after','occurred_at'];
    protected function casts(): array { return ['entry_type'=>CreditEntryType::class,'amount'=>'decimal:2','balance_before'=>'decimal:2','balance_after'=>'decimal:2','occurred_at'=>'datetime']; }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
}
