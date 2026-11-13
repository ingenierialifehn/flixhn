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
            'MÃºsica' => 'Música',
            '├║' => 'ú',
            '├│' => 'ó',
            '├í' => 'á',
            '├®' => 'é',
            '├¡' => 'í',
            '├▒' => 'ñ',
            '├Ñ' => 'Ñ',
            'Ãº' => 'ú',
            'Ã³' => 'ó',
            'Ã¡' => 'á',
            'Ã©' => 'é',
            'Ã­' => 'í',
            'Ã±' => 'ñ',
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
            return $value;
        }

        if (!empty($this->id)) {
            $local = app(\App\Services\TvLogoService::class)->findExistingLocalLogo($this->id);
            if ($local) {
                return $local;
            }
        }

        // Si la URL en BD está vacía o apunta al CDN caído tvlogos.b-cdn.net o imgur, resolver candidato curado o iptv-org
        if (empty($value) || str_contains($value, 'tvlogos.b-cdn.net') || str_contains($value, 'i.imgur.com')) {
            try {
                $candidate = app(\App\Services\TvLogoService::class)->resolveCandidateLogoUrl($this->name, null, $this->tvg_id);
                if (!empty($candidate)) {
                    return $candidate;
                }
            } catch (\Throwable $e) {}
        }

        return $value;
    }

    public function tvSource(): BelongsTo
    {
        return $this->belongsTo(TvSource::class, 'tv_source_id');
    }
}
