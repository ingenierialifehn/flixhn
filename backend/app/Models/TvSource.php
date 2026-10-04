<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TvSource extends Model
{
    use HasFactory;

    protected $table = 'tv_sources';

    protected $fillable = [
        'name',
        'type',
        'url',
        'user_agent',
        'referer_mode',
        'referrer_header',
        'stream_limit',
        'group_filter',
        'import_guide_from_m3u',
        'preferred_image_source',
        'allow_channel_number_mapping',
        'tags',
        'is_active',
        'channels_count',
        'last_synced_at',
    ];

    protected $casts = [
        'import_guide_from_m3u' => 'boolean',
        'allow_channel_number_mapping' => 'boolean',
        'is_active' => 'boolean',
        'stream_limit' => 'integer',
        'channels_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function tvChannels(): HasMany
    {
        return $this->hasMany(TvChannel::class, 'tv_source_id');
    }
}
