<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaItem extends Model
{
    use HasFactory;

    protected $table = 'media_items';

    protected $fillable = [
        'title',
        'original_title',
        'type',
        'provider',
        'external_id',
        'overview',
        'poster_url',
        'backdrop_url',
        'release_year',
        'genre',
        'duration_seconds',
        'file_path',
        'stream_url',
        'quality',
        'file_size',
        'title_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'release_year' => 'integer',
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
    ];

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function downloadTasks(): HasMany
    {
        return $this->hasMany(OttDownloadTask::class, 'media_item_id');
    }
}
