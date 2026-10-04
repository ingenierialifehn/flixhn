<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'category_id',
        'description',
        'poster_url',
        'backdrop_url',
        'release_year',
        'genre',
        'is_featured',
        'stream_path',
        'source_path',
        'duration_seconds',
        'title',
        'overview',
        'stream_url',
        'year',
    ];

    protected $appends = [
        'title',
        'overview',
        'stream_url',
        'year',
        'is_new',
    ];

    public function getIsNewAttribute(): bool
    {
        if (empty($this->created_at)) {
            return false;
        }
        return $this->created_at->diffInDays(now()) <= 14;
    }

    public function getTitleAttribute(): ?string
    {
        return $this->attributes['name'] ?? null;
    }

    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
    }

    public function getOverviewAttribute(): ?string
    {
        return $this->attributes['description'] ?? null;
    }

    public function setOverviewAttribute(?string $value): void
    {
        $this->attributes['description'] = $value;
    }

    public function getStreamUrlAttribute(): ?string
    {
        $path = $this->attributes['stream_path'] ?? null;
        if (!$path) return null;

        // Normalización para URLs remotas de Emby Server
        if (str_contains($path, '/Videos/')) {
            // Si ya apunta a la ruta /original nativa, se conserva directamente
            if (str_contains($path, '/original')) {
                return $path;
            }

            // Para rutas de stream, garantizar static=true, MediaSourceId y DeviceId=flixhn-web-client
            if (str_contains($path, '/stream')) {
                // Estandarizar /stream.mp4 a /stream
                $path = preg_replace('#/Videos/([^/?]+)/stream\.mp4(\?|$)#', '/Videos/$1/stream$2', $path);

                preg_match('#/Videos/([^/?]+)#', $path, $matches);
                $videoId = $matches[1] ?? null;

                $glue = str_contains($path, '?') ? '&' : '?';
                if (!str_contains($path, 'static=true')) {
                    $path .= $glue . 'static=true';
                    $glue = '&';
                }
                if ($videoId && !str_contains($path, 'MediaSourceId=')) {
                    $path .= $glue . "MediaSourceId={$videoId}";
                    $glue = '&';
                }
                if (!str_contains($path, 'DeviceId=')) {
                    $path .= $glue . 'DeviceId=flixhn-web-client';
                    $glue = '&';
                }
            }
        }

        return $path;
    }

    public function setStreamUrlAttribute(?string $value): void
    {
        $this->attributes['stream_path'] = $value;
    }

    public function getYearAttribute(): ?int
    {
        $val = isset($this->attributes['release_year']) ? (int) $this->attributes['release_year'] : 0;
        return ($val > 1800) ? $val : null;
    }

    public function setYearAttribute(?int $value): void
    {
        $this->attributes['release_year'] = $value;
    }

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'release_year' => 'integer',
            'duration_seconds' => 'integer',
            'category_id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)->orderBy('season_number', 'asc');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('episode_number', 'asc');
    }

    public function playbackProgresses(): HasMany
    {
        return $this->hasMany(PlaybackProgress::class);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeMovies(Builder $query): Builder
    {
        return $query->where('type', 'movie');
    }

    public function scopeSeries(Builder $query): Builder
    {
        return $query->where('type', 'series');
    }
}
