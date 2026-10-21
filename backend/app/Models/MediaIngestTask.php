<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaIngestTask extends Model
{
    use HasFactory;

    protected $table = 'media_ingest_tasks';

    protected $fillable = [
        'source_url',
        'provider',
        'title',
        'target_folder',
        'status',
        'progress',
        'download_speed',
        'eta',
        'quality',
        'auto_index',
        'output_file',
        'error_message',
        'pid',
        'media_item_id',
        'title_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'auto_index' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'pid' => 'integer',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function titleModel(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'downloading', 'processing']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
