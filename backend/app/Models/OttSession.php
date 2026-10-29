<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OttSession extends Model
{
    use HasFactory;

    protected $table = 'ott_sessions';

    protected $fillable = [
        'user_id',
        'provider',
        'client_id',
        'refresh_token',
        'access_token',
        'token_type',
        'expires_at',
        'session_metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'session_metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUserNameAttribute(): ?string
    {
        return $this->attributes['user_name'] 
            ?? ($this->session_metadata['user_name'] 
            ?? ($this->user?->name 
            ?? 'Usuario Netflix'));
    }

    /**
     * Devuelve la sesión activa real para el usuario o del sistema para la plataforma solicitada.
     */
    public static function getActiveSession(?string $platform = null): ?self
    {
        $target = $platform ? strtolower(trim($platform)) : null;
        if ($target === 'disney_plus' || $target === 'disneyplus') {
            $target = 'disney';
        }

        $userId = null;
        try {
            $userId = auth()->id() ? (int) auth()->id() : null;
        } catch (\Throwable $e) {}

        $query = self::query();
        if ($target && $target !== 'all') {
            $query->where(function ($q) use ($target) {
                $q->where('provider', $target);
                if ($target === 'disney') {
                    $q->orWhere('provider', 'disney_plus');
                }
            });
        }

        if ($userId) {
            $session = (clone $query)->where('user_id', $userId)->latest()->first();
            if ($session) {
                return $session;
            }
        }

        return $query->latest()->first();
    }
}
