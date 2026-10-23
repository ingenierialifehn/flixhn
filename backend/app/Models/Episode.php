<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $appends = [
        'stream_url',
        'emby_id',
    ];

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
            return $path;
        }

        // Si es una ruta física local de archivo, devolver el endpoint nativo de streaming
        if ($this->id && (str_starts_with($path, '/') || str_starts_with($path, 'storage/') || preg_match('/^[a-zA-Z]:/', $path))) {
            return "/api/media/{$this->id}/stream";
        }

        return $path;
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
