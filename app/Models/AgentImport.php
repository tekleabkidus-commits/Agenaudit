<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentImport extends Model
{
    protected $fillable = ['uploaded_by','original_name','disk','path','status','total_rows','valid_rows','error_rows','new_rows','unchanged_rows','move_rows','confirmed_at'];
    protected function casts(): array { return ['confirmed_at'=>'datetime']; }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function rows(): HasMany { return $this->hasMany(AgentImportRow::class); }
}
