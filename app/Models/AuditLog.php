<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id','action','auditable_type','auditable_id','before','after','metadata','ip_address','user_agent','created_at'];
    protected function casts(): array { return ['before'=>'array','after'=>'array','metadata'=>'array','created_at'=>'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
