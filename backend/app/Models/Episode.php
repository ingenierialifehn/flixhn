<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use App\Observers\EpisodeObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([EpisodeObserver::class])]
class Episode extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_id',
        'season_id',
        'episode_number',
        'title',
        'description',
        'stream_path',
        'duration_seconds',
        'thumbnail_url',
        'sort_title',
        'release_date',
        'community_rating',
        'parental_rating',
        'imdb_id',
        'tmdb_id',
        'tvdb_id',
        'is_locked',
        'locked_fields',
        'file_path',
        'source_path',
        'source_type',
    ];

    protected $appends = [
        'stream_url',
        'emby_id',
    ];

    public function getStreamPathAttribute(?string $value): ?string
    {
        if ($this->id) {
            $filePath = $this->attributes['file_path'] ?? null;
            $sourcePath = $this->attributes['source_path'] ?? null;
            $isLocal = ($this->attributes['source_type'] ?? 'local') === 'local'
                || !empty($filePath)
                || !empty($sourcePath)
                || ($value && (str_starts_with($value, '/mnt/') || str_starts_with($value, '/storage/') || str_starts_with($value, 'storage/') || str_starts_with($value, '/api/stream/')));

            if ($isLocal && !str_contains((string) $value, '/Videos/')) {
                return "/api/stream/episode/{$this->id}";
            }
        }
        return $value;
    }

    public function getStreamUrlAttribute(): ?string
    {
        $filePath = $this->attributes['file_path'] ?? null;
        $sourcePath = $this->attributes['source_path'] ?? null;
        $streamPath = $this->attributes['stream_path'] ?? null;
        $path = $streamPath ?? $filePath ?? $sourcePath ?? null;
        if (!$path) return null;

        // Si es archivo local directo, devolver el endpoint nativo de streaming de FlixHN
        if ($this->id) {
            $isLocal = ($this->attributes['source_type'] ?? 'local') === 'local'
                || !empty($filePath)
                || !empty($sourcePath)
                || str_starts_with($path, '/mnt/')
                || str_starts_with($path, '/storage/')
                || str_starts_with($path, 'storage/')
                || preg_match('/^[a-zA-Z]:/', $path)
                || str_starts_with($path, '/api/stream/')
                || str_starts_with($path, '/api/episodes/')
                || str_starts_with($path, '/api/media/');

            if ($isLocal && !str_contains($path, '/Videos/')) {
                return "/api/stream/episode/{$this->id}";
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
            return "/api/stream/episode/{$this->id}";
        }

        return $path;
    }

    public function getThumbnailUrlAttribute(?string $value): ?string
    {
        if (empty($value) || str_contains($value, '45.4.87.126') || str_ends_with($value, 'index.jpeg') || str_ends_with($value, 'index2.jpeg')) {
            return '/placeholder-poster.png';
        }
        return $value;
    }

    public function setStreamUrlAttribute(?string $value): void
    {
        $this->attributes['stream_path'] = $value;
    }

    public function getEmbyIdAttribute(): ?string
    {
        $stream = $this->attributes['stream_path'] ?? null;
        if (!empty($stream) && preg_match('#/(?:Videos|Items)/([a-zA-Z0-9_-]+)#i', $stream, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'duration_seconds' => 'integer',
            'community_rating' => 'float',
            'is_locked' => 'boolean',
            'locked_fields' => 'array',
        ];
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function playbackProgresses(): HasMany
    {
        return $this->hasMany(PlaybackProgress::class);
    }
}
