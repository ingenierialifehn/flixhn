<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class DeviceSession extends Model
{
    use HasFactory;

    protected $table = 'device_sessions';

    protected $fillable = [
        'device_id',
        'custom_name',
        'user_id',
        'client_identifier',
        'device_type',
        'os_name',
        'browser_name',
        'ip_address',
        'current_media_type',
        'current_media_title',
        'current_media_poster',
        'playback_position_seconds',
        'media_duration_seconds',
        'playback_state',
        'is_blocked',
        'session_started_at',
        'last_heartbeat_at',
        'user_agent',
        'screen_resolution',
        'connection_type',
        'bitrate',
        'remote_command',
        'command_issued_at',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'playback_position_seconds' => 'integer',
        'media_duration_seconds' => 'integer',
        'session_started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'command_issued_at' => 'datetime',
    ];

    protected $appends = [
        'is_online',
        'progress_percent',
        'session_duration_formatted',
        'session_duration_seconds',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determina si el dispositivo está actualmente en línea (heartbeat en los últimos 35 segundos y no parado)
     */
    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->diffInSeconds(now()) <= 35 && $this->playback_state !== 'stopped';
    }

    /**
     * Porcentaje de reproducción actual
     */
    public function getProgressPercentAttribute(): int
    {
        if ($this->media_duration_seconds <= 0) {
            return 0;
        }

        return min(100, (int) round(($this->playback_position_seconds / $this->media_duration_seconds) * 100));
    }

    /**
     * Duración en segundos de la sesión
     */
    public function getSessionDurationSecondsAttribute(): int
    {
        if (!$this->session_started_at) {
            return 0;
        }

        return max(0, (int) $this->session_started_at->diffInSeconds(now()));
    }

    /**
     * Duración formateada de la sesión activa (HH:MM:SS)
     */
    public function getSessionDurationFormattedAttribute(): string
    {
        $seconds = $this->session_duration_seconds;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
