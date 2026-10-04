<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'api_key',
        'is_active',
        'is_master',
    ];

    protected $casts = [
        'port' => 'integer',
        'is_active' => 'boolean',
        'is_master' => 'boolean',
    ];

    /**
     * URL completa del servidor de medios
     */
    public function getBaseUrlAttribute(): string
    {
        $protocol = str_contains($this->ip_address, 'https://') ? 'https://' : 'http://';
        $host = preg_replace('#^https?://#', '', $this->ip_address);
        $host = trim($host, '/');
        return "{$protocol}{$host}:{$this->port}";
    }
}
