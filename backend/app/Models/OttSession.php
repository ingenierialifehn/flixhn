<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OttSession extends Model
{
    use HasFactory;

    protected $table = 'ott_sessions';

    protected $fillable = [
        'user_id',
        'provider',
        'client_id',
        'refresh_token',
        'access_token',
        'token_type',
        'expires_at',
        'session_metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'session_metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}