<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBrandHistory extends Model
{
    protected $fillable = ['agent_id','from_brand_id','to_brand_id','changed_by','reason','changed_at'];
    protected function casts(): array { return ['changed_at'=>'datetime']; }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function fromBrand(): BelongsTo { return $this->belongsTo(Brand::class, 'from_brand_id'); }
    public function toBrand(): BelongsTo { return $this->belongsTo(Brand::class, 'to_brand_id'); }
    public function changer(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
