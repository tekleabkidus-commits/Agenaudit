<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePermission extends Model
{
    protected $fillable = ['user_id','correction_fields'];
    protected function casts(): array { return ['correction_fields'=>'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function canRequestCorrection(string $field): bool { return in_array($field, $this->correction_fields ?? [], true); }
}
