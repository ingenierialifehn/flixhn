<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ApiKey extends Model
{
    use HasFactory;

    protected $table = 'api_keys';

    protected $fillable = [
        'name',
        'key',
        'truncated_key',
        'user_id',
        'scopes',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Usuario administrador creador de la clave (opcional)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Genera una clave segura criptográficamente única
     */
    public static function generateSecureKey(): string
    {
        do {
            $key = bin2hex(random_bytes(24)); // 48 caracteres hexadecimales seguros
        } while (static::where('key', $key)->exists());

        return $key;
    }

    /**
     * Formatea la clave truncada para visualización segura en interfaces
     */
    public static function truncateKey(string $key): string
    {
        if (strlen($key) < 16) {
            return $key;
        }
        return substr($key, 0, 8) . '...' . substr($key, -4);
    }

    /**
     * Comprueba si la clave está activa y no ha expirado
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Comprueba si la clave ya caducó
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Comprueba si la clave posee un alcance/scope específico
     */
    public function hasScope(string $scope): bool
    {
        if (empty($this->scopes) || in_array('*', $this->scopes, true)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }
}
