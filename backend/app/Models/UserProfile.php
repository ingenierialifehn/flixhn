<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserProfile extends Model
{
    use HasFactory;

    protected $table = 'user_profiles';

    protected $fillable = [
        'user_id',
        'name',
        'avatar',
        'avatar_color',
        'is_kids',
        'pin',
        'preferences',
    ];

    /**
     * Mutador de retrocompatibilidad para mapear avatar_color a avatar
     */
    public function setAvatarColorAttribute($value): void
    {
        $this->attributes['avatar'] = $value;
    }

    protected function casts(): array
    {
        return [
            'is_kids' => 'boolean',
            'preferences' => 'array',
        ];
    }

    protected $hidden = [
        'pin', // Ocultar el PIN en serializaciones generales por seguridad
    ];

    protected $appends = [
        'avatar_color',
        'has_pin',
    ];

    /**
     * Compatibilidad hacia atrás con vistas frontend previas que usan avatar_color
     */
    public function getAvatarColorAttribute(): string
    {
        $avatar = $this->attributes['avatar'] ?? '#E50914';
        return str_starts_with($avatar, '#') ? $avatar : '#E50914';
    }

    /**
     * Informar a la interfaz si el perfil requiere PIN sin revelar el código numérico
     */
    public function getHasPinAttribute(): bool
    {
        return !empty($this->attributes['pin']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playbackProgresses(): HasMany
    {
        return $this->hasMany(PlaybackProgress::class, 'profile_id');
    }
}
