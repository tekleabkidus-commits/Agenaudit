<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['transaction_id','actor_id','event_type','message','data','created_at'];
    protected function casts(): array { return ['data'=>'array','created_at'=>'datetime']; }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
