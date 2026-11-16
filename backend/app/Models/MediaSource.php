<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class MediaSource extends Model
{
    use HasFactory;

    protected $table = 'media_sources';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'base_url',
        'api_key',
        'auth_headers',
        'priority',
        'is_active',
        'is_downloadable',
        'settings',
    ];

    protected $casts = [
        'auth_headers' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_downloadable' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Trabajos de descarga asociados a esta fuente.
     */
    public function downloadJobs(): HasMany
    {
        return $this->hasMany(DownloadJob::class, 'media_source_id');
    }

    /**
     * Scope para fuentes activas ordenadas por prioridad.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('priority', 'asc');
    }

    /**
     * Scope para fuentes que permiten descargas.
     */
    public function scopeDownloadable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_downloadable', true);
    }
}
