<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionConfirmation extends Model
{
    public $timestamps = false;
    protected $fillable = ['transaction_id','user_id','kind','metadata','confirmed_at'];
    protected function casts(): array { return ['metadata'=>'array','confirmed_at'=>'datetime']; }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
