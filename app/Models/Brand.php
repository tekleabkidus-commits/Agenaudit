<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'slug', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function agents(): HasMany { return $this->hasMany(Agent::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function receivingAccounts(): BelongsToMany { return $this->belongsToMany(ReceivingAccount::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps(); }
}
