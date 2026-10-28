<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OttContent extends Model
{
    use HasFactory;

    protected $table = 'ott_contents';

    protected $fillable = [
        'title',
        'original_title',
        'poster_path',
        'backdrop_path',
        'platform_id',
        'quality_available',
        'download_status',
        'local_file_path',
        'last_sync_date',
    ];

    protected $casts = [
        'last_sync_date' => 'datetime',
    ];

    public function scopeCompleted($query)
    {
        return $query->where('download_status', 'completed');
    }

    public function scopeDownloading($query)
    {
        return $query->where('download_status', 'downloading');
    }

    public function scopePending($query)
    {
        return $query->where('download_status', 'pending');
    }

    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('platform_id', $platform);
    }
}
