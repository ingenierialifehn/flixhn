<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
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

        return $value;
    }

    public function tvSource(): BelongsTo
    {
        return $this->belongsTo(TvSource::class, 'tv_source_id');
    }
}
