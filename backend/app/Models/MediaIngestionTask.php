<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaIngestionTask extends Model
{
    use HasFactory;

    protected $table = 'media_ingestion_tasks';

    protected $fillable = [
        'content_type',
        'title',
        'release_year',
        'imdb_id',
        'tmdb_id',
        'overview',
        'season_number',
        'episode_number',
        'episode_title',
        'source_url',
        'source_type',
        'target_disk',
        'target_folder',
        'output_file',
        'quality',
        'status',
        'progress',
        'download_speed',
        'eta',
        'file_size',
        'pid',
        'error_log',
        'logs',
        'title_id',
        'episode_id',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'release_year' => 'integer',
        'season_number' => 'integer',
        'episode_number' => 'integer',
        'progress' => 'integer',
        'file_size' => 'integer',
        'pid' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function titleModel(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function episodeModel(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'downloading', 'processing'], true);
    }

    public function appendLog(string $message): void
    {
        $line = '[' . now()->format('H:i:s') . '] ' . trim($message);
        $current = trim((string) $this->logs);
        $updated = $current === '' ? $line : ($current . "\n" . $line);

        if (strlen($updated) > 24000) {
            $updated = substr($updated, -24000);
        }

        $this->logs = $updated;
    }
}