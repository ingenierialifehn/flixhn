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

    protected $appends = [
        'source_platform',
        'rating',
        'genres',
    ];

    public function getRatingAttribute(): float
    {
        return (float) ($this->metadata['rating'] ?? 8.5);
    }

    public function getGenresAttribute(): array
    {
        return $this->metadata['genres'] ?? (!empty($this->genre) ? array_map('trim', explode('/', $this->genre)) : ['Acción', 'Streaming']);
    }

    public function getProviderAttribute(?string $value): string
    {
        return !empty($value) ? $value : 'local';
    }

    public function getSourcePlatformAttribute(): string
    {
        return $this->provider;
    }

    public function setSourcePlatformAttribute(?string $value): void
    {
        $this->attributes['provider'] = $value ?: 'local';
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function downloadTasks(): HasMany
    {
        return $this->hasMany(OttDownloadTask::class, 'media_item_id');
    }
}
