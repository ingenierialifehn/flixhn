<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use App\Observers\TvChannelObserver;
use App\Observers\ChannelObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([TvChannelObserver::class, ChannelObserver::class])]
class TvChannel extends Model
{
    use HasFactory;

    protected $table = 'tv_channels';

    protected $fillable = [
        'tv_source_id',
        'name',
        'stream_url',
        'logo_url',
        'group_title',
        'channel_number',
        'tvg_id',
        'tvg_name',
        'is_active',
        'is_online',
        'status',
        'sort_title',
        'overview',
        'community_rating',
        'parental_rating',
        'genres',
        'tags',
        'metadata_language',
        'country',
        'is_locked',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'community_rating' => 'float',
        'genres' => 'array',
        'tags' => 'array',
        'is_locked' => 'boolean',
    ];

    protected $appends = [
        'is_online',
        'logo',
    ];

    public function getIsOnlineAttribute(): bool
    {
        return array_key_exists('is_online', $this->attributes) 
            ? (bool)$this->attributes['is_online'] 
            : true;
    }

    public function getLogoAttribute(): ?string
    {
        return $this->logo_url;
    }

    /**
     * Sanitiza caracteres mojibake UTF-8 comunes en nombres y categorías.
     */
    public static function fixMojibake(?string $str): ?string
    {
        if (empty($str)) return $str;

        $replacements = [
            'M├║sica' => 'Música',
            'M├ºsica' => 'Música',
            'Música' => 'Música',
            '├║' => 'ú',
            '├│' => 'ó',
            '├í' => 'á',
            '├®' => 'é',
            '├¡' => 'í',
            '├▒' => 'ñ',
            '├Ñ' => 'Ñ',
            'ú' => 'ú',
            'ó' => 'ó',
            'á' => 'á',
            'é' => 'é',
            'í' => 'í',
            'ñ' => 'ñ',
        ];

        return strtr($str, $replacements);
    }

    public function getGroupTitleAttribute(?string $value): ?string
    {
        return self::fixMojibake($value);
    }

    public function getNameAttribute(?string $value): ?string
    {
        return self::fixMojibake($value);
    }

    public function getLogoUrlAttribute(?string $value): ?string
    {
        if (!empty($value) && str_starts_with($value, '/storage/')) {
            $relPath = preg_replace('#^/storage/#', '', $value);
            $pubPath = public_path('storage/' . $relPath);
            $appPubPath = storage_path('app/public/' . $relPath);
            if (file_exists($pubPath) || file_exists($appPubPath)) {
                return $value;
            }
            // Si el archivo físico en /storage/ no existe en disco, devolver endpoint dinámico que resuelve desde iptv_logos_index o SVG
            return "/api/tv-channels/{$this->id}/logo";
        }

        if (!empty($this->id)) {
            $local = app(\App\Services\TvLogoService::class)->findExistingLocalLogo($this->id);
            if ($local) {
                return $local;
            }
        }

        // Si la URL en BD está vacía o apunta al CDN caído tvlogos.b-cdn.net o imgur, devolver endpoint dinámico
        if (empty($value) || str_contains($value, 'tvlogos.b-cdn.net') || str_contains($value, 'i.imgur.com')) {
            return !empty($this->id) ? "/api/tv-channels/{$this->id}/logo" : $value;
        }

        return $value;
    }

    public function tvSource(): BelongsTo
    {
        return $this->belongsTo(TvSource::class, 'tv_source_id');
    }
}
