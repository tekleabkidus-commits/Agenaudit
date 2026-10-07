<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;
    protected $fillable = ['brand_id','agent_id','agent_id_normalized','username','username_normalized','is_active','credit_enabled','credit_limit','credit_due_days','commission_enabled','commission_monthly_limit','metadata'];
    protected function casts(): array
    {
        return [
            'is_active'=>'boolean','credit_enabled'=>'boolean','credit_limit'=>'decimal:2','credit_due_days'=>'integer',
            'commission_enabled'=>'boolean','commission_monthly_limit'=>'integer','metadata'=>'array',
        ];
    }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function creditLedgerEntries(): HasMany { return $this->hasMany(CreditLedgerEntry::class); }
    public function creditRecords(): HasMany { return $this->hasMany(CreditRecord::class); }
    public function brandHistories(): HasMany { return $this->hasMany(AgentBrandHistory::class); }
}
