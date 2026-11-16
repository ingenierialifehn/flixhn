<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class DownloadJob extends Model
{
    use HasFactory;

    protected $table = 'download_jobs';

    protected $fillable = [
        'job_id',
        'content_id',
        'media_item_id',
        'title_id',
        'media_source_id',
        'user_id',
        'title',
        'provider',
        'source_url',
        'storage_path',
        'hls_path',
        'hls_playlist_url',
        'total_bytes',
        'downloaded_bytes',
        'progress',
        'download_speed',
        'eta',
        'status',
        'priority',
        'retry_count',
        'max_retries',
        'error_message',
        'segments_ready',
        'can_play_progressive',
        'duration_seconds',
        'quality',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'progress' => 'float',
        'total_bytes' => 'integer',
        'downloaded_bytes' => 'integer',
        'priority' => 'integer',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'segments_ready' => 'integer',
        'can_play_progressive' => 'boolean',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = [
        'formatted_total_bytes',
        'formatted_downloaded_bytes',
        'is_playable',
    ];

    public function mediaSource(): BelongsTo
    {
        return $this->belongsTo(MediaSource::class, 'media_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function titleModel(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['QUEUED', 'DOWNLOADING', 'PROCESSING']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'READY';
    }

    public function isFailed(): bool
    {
        return $this->status === 'ERROR';
    }

    public function getIsPlayableAttribute(): bool
    {
        return $this->status === 'READY' || ($this->can_play_progressive && $this->segments_ready >= 2);
    }

    public function getFormattedTotalBytesAttribute(): string
    {
        return $this->formatBytes($this->total_bytes);
    }

    public function getFormattedDownloadedBytesAttribute(): string
    {
        return $this->formatBytes($this->downloaded_bytes);
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0.0 MB';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['QUEUED', 'DOWNLOADING', 'PROCESSING']);
    }

    public function scopeByContent(Builder $query, string $contentId): Builder
    {
        return $query->where('content_id', $contentId);
    }
}
