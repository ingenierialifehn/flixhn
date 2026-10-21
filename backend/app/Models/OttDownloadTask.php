<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OttDownloadTask extends Model
{
    use HasFactory;

    protected $table = 'ott_download_tasks';

    protected $fillable = [
        'provider',
        'ott_account_id',
        'title',
        'media_type',
        'external_id',
        'season_number',
        'episode_number',
        'quality',
        'status',
        'progress',
        'download_speed',
        'eta',
        'total_bytes',
        'downloaded_bytes',
        'file_path',
        'error_message',
        'metadata',
        'media_item_id',
        'title_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'season_number' => 'integer',
        'episode_number' => 'integer',
        'total_bytes' => 'integer',
        'downloaded_bytes' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(OttProviderAccount::class, 'ott_account_id');
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function titleModel(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }
}
