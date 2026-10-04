<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TvGuideSource extends Model
{
    use HasFactory;

    protected $table = 'tv_guide_sources';

    protected $fillable = [
        'name',
        'type',
        'url',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
