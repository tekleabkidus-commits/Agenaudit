<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentImportRow extends Model
{
    protected $fillable = ['agent_import_id','row_number','brand_name','agent_id','agent_username','action','status','errors','resolved_brand_id','resolved_agent_id'];
    protected function casts(): array { return ['errors'=>'array']; }
    public function import(): BelongsTo { return $this->belongsTo(AgentImport::class, 'agent_import_id'); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class, 'resolved_brand_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class, 'resolved_agent_id'); }
}
