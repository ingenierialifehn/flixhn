<?php

namespace App\Models;

/**
 * Clase Profile para retrocompatibilidad con controladores y servicios existentes.
 * Extiende de UserProfile apuntando a la tabla 'user_profiles'.
 */
class Profile extends UserProfile
{
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
     * Mutador para permitir guardar avatar_color mapeándolo a avatar
     */
    public function setAvatarColorAttribute($value): void
    {
        $this->attributes['avatar'] = $value;
    }
}
