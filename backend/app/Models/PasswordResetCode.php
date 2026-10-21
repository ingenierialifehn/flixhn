<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    use HasFactory;

    protected $table = 'password_reset_codes';

    public $timestamps = false;

    protected $fillable = [
        'identifier',
        'code',
        'channel',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Comprobar si el código ha caducado
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}
