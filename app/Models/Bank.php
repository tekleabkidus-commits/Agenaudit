<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use HasFactory;
    protected $fillable = ['code', 'name', 'aliases', 'check_et_code', 'check_et_enabled', 'check_et_requires_account', 'check_et_account_source', 'is_active'];
    protected function casts(): array { return ['aliases'=>'array','check_et_enabled'=>'boolean','check_et_requires_account'=>'boolean','is_active'=>'boolean']; }
    public function receivingAccounts(): HasMany { return $this->hasMany(ReceivingAccount::class); }
}
