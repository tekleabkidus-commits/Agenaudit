<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReceivingAccount extends Model
{
    use HasFactory;
    protected $fillable = ['bank_id','account_number','normalized_account_number','account_name','normalized_account_name','name_aliases','account_type','is_active'];
    protected function casts(): array { return ['name_aliases'=>'array','is_active'=>'boolean']; }
    public function bank(): BelongsTo { return $this->belongsTo(Bank::class); }
    public function brands(): BelongsToMany { return $this->belongsToMany(Brand::class); }
}
