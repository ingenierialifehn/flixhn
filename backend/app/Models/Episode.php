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
    ];

    protected $appends = [
        'stream_url',
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
        }

        return $path;
    }

    public function setStreamUrlAttribute(?string $value): void
    {
        $this->attributes['stream_path'] = $value;
    }

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'duration_seconds' => 'integer',
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
