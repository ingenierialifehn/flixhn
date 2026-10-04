<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackProgress extends Model
{
    use HasFactory;

    protected $table = 'playback_progress';

    protected $fillable = [
        'profile_id',
        'title_id',
        'episode_id',
        'current_seconds',
        'duration_seconds',
        'last_watched_at',
    ];

    protected function casts(): array
    {
        return [
            'current_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'last_watched_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
