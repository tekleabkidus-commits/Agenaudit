<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSession extends Model
{
    protected $fillable = ['user_id','token_hash','device_label','user_agent','ip_address','last_seen_at','expires_at','revoked_at'];
    protected function casts(): array { return ['last_seen_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function isUsable(): bool { return $this->revoked_at === null && $this->expires_at->isFuture() && $this->user?->is_active; }
}
