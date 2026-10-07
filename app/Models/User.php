<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'is_active', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool { return $this->role === UserRole::Admin; }
    public function isEmployee(): bool { return $this->role === UserRole::Employee; }

    public function deviceSessions(): HasMany { return $this->hasMany(DeviceSession::class); }
    public function permissions(): HasOne { return $this->hasOne(EmployeePermission::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class, 'employee_id'); }
}
