<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'customer_name',
        'phone',
        'contract_number',
        'assigned_ip',
        'max_screens',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'max_screens' => 'integer',
        ];
    }

    /**
     * Mapeo estricto del rol: 'admin' o 'client'.
     */
    public function getRoleAttribute($value): string
    {
        return $value === 'admin' ? 'admin' : 'client';
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    public function userProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    public function isAdmin(): bool
    {
        $rawRole = $this->attributes['role'] ?? null;
        return $rawRole === 'admin';
    }

    public function isClient(): bool
    {
        return !$this->isAdmin();
    }

    public function isSubscriber(): bool
    {
        return $this->isClient();
    }
}
