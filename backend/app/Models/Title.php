<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use App\Observers\TitleObserver;
use App\Observers\MediaObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([TitleObserver::class, MediaObserver::class])]
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
        'sort_title',
        'release_date',
        'community_rating',
        'parental_rating',
        'custom_rating',
        'video_format_3d',
        'imdb_id',
        'tmdb_id',
        'tvdb_id',
        'cast',
        'studios',
        'tags',
        'metadata_language',
        'country',
        'is_locked',
        'locked_fields',
        'provider',
        'file_path',
        'source_type',
    ];

    protected $appends = [
        'title',
        'overview',
        'stream_url',
        'year',
        'is_new',
        'source_platform',
        'emby_id',
    ];

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

    public function mediaItem(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MediaItem::class, 'title_id');
    }

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

    public function getStreamPathAttribute(?string $value): ?string
    {
        if ($this->id) {
            $provider = $this->provider;
            $sourcePath = $this->attributes['source_path'] ?? null;
            $filePath = $this->attributes['file_path'] ?? null;

            $isLocal = ($provider === 'local')
                || ($this->attributes['source_type'] ?? null) === 'local'
                || (!empty($sourcePath) && str_starts_with($sourcePath, '/mnt/'))
                || (!empty($filePath) && str_starts_with($filePath, '/mnt/'))
                || (!empty($sourcePath) && (str_starts_with($sourcePath, '/storage/') || str_starts_with($sourcePath, 'storage/')))
                || (!empty($filePath) && (str_starts_with($filePath, '/storage/') || str_starts_with($filePath, 'storage/')))
                || ($value && (str_starts_with($value, '/api/stream/') || str_starts_with($value, '/api/media/')));

            if ($isLocal && !str_contains((string) $value, '/Videos/')) {
                return "/api/stream/title/{$this->id}";
            }
        }
        return $value;
    }

    public function getStreamUrlAttribute(): ?string
    {
        // Si el registro es local ($this->provider === 'local' o $this->source_path inicia con /mnt/), devuelve SIEMPRE /api/stream/title/{$this->id}
        if ($this->id) {
            $provider = $this->provider;
            $sourcePath = $this->attributes['source_path'] ?? null;
            $filePath = $this->attributes['file_path'] ?? null;
            $streamPath = $this->attributes['stream_path'] ?? null;

            $isLocal = ($provider === 'local')
                || ($this->attributes['source_type'] ?? null) === 'local'
                || (!empty($sourcePath) && str_starts_with($sourcePath, '/mnt/'))
                || (!empty($filePath) && str_starts_with($filePath, '/mnt/'))
                || (!empty($sourcePath) && (str_starts_with($sourcePath, '/storage/') || str_starts_with($sourcePath, 'storage/')))
                || (!empty($filePath) && (str_starts_with($filePath, '/storage/') || str_starts_with($filePath, 'storage/')))
                || ($streamPath && (str_starts_with($streamPath, '/api/stream/') || str_starts_with($streamPath, '/api/media/')));

            $pathCheck = $streamPath ?? $filePath ?? $sourcePath ?? '';
            if ($isLocal && !str_contains((string)$pathCheck, '/Videos/')) {
                return "/api/stream/title/{$this->id}";
            }
        }

        $sourcePath = $this->attributes['source_path'] ?? null;
        $filePath = $this->attributes['file_path'] ?? null;
        $streamPath = $this->attributes['stream_path'] ?? null;
        $path = $streamPath ?? $filePath ?? $sourcePath ?? null;
        if (!$path) return null;

        // Si el origen es almacenamiento local directo, devolver el endpoint nativo de streaming de FlixHN
        if ($this->id) {
            $isLocal = ($this->attributes['provider'] ?? 'local') === 'local'
                || ($this->attributes['source_type'] ?? null) === 'local'
                || !empty($sourcePath)
                || !empty($filePath)
                || str_starts_with($path, '/mnt/')
                || str_starts_with($path, '/storage/')
                || str_starts_with($path, 'storage/')
                || preg_match('/^[a-zA-Z]:/', $path)
                || str_starts_with($path, '/api/stream/')
                || str_starts_with($path, '/api/media/');

            if ($isLocal && !str_contains($path, '/Videos/')) {
                return "/api/stream/title/{$this->id}";
            }
        }

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
            return $path;
        }

        if ($this->id && (str_starts_with($path, '/') || str_starts_with($path, 'storage/') || preg_match('/^[a-zA-Z]:/', $path))) {
            return "/api/stream/title/{$this->id}";
        }

        return $path;
    }

    public function getPosterUrlAttribute(?string $value): ?string
    {
        if (empty($value) || str_contains($value, '45.4.87.126') || str_ends_with($value, 'index.jpeg') || str_ends_with($value, 'index2.jpeg')) {
            return '/placeholder-poster.png';
        }
        return $value;
    }

    public function getBackdropUrlAttribute(?string $value): ?string
    {
        if (empty($value) || str_contains($value, '45.4.87.126') || str_ends_with($value, 'index.jpeg') || str_ends_with($value, 'index2.jpeg')) {
            return '/placeholder-poster.png';
        }
        return $value;
    }

    public function getEmbyIdAttribute(): ?string
    {
        if (!empty($this->source_path) && preg_match('/remote:\/\/([a-zA-Z0-9_-]+)/i', $this->source_path, $m)) {
            return $m[1];
        }
        $stream = $this->attributes['stream_path'] ?? null;
        if (!empty($stream) && preg_match('#/(?:Videos|Items)/([a-zA-Z0-9_-]+)#i', $stream, $m)) {
            return $m[1];
        }
        return null;
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
            'community_rating' => 'float',
            'is_locked' => 'boolean',
            'cast' => 'array',
            'studios' => 'array',
            'tags' => 'array',
            'locked_fields' => 'array',
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
