<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class OttProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'account_name',
        'credentials',
        'status',
        'last_sync_at',
        'auto_download',
        'quality_profile',
        'session_metadata',
        'error_message',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'auto_download' => 'boolean',
        'session_metadata' => 'array',
    ];

    protected $hidden = [
        'credentials',
    ];

    /**
     * Encripta y almacena credenciales de acceso de forma segura.
     */
    public function setDecryptedCredentials(array $credentials): void
    {
        $this->credentials = Crypt::encryptString(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Desencripta y recupera credenciales de acceso seguras.
     */
    public function getDecryptedCredentials(): ?array
    {
        if (empty($this->credentials)) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($this->credentials);
            return json_decode($decrypted, true);
        } catch (\Throwable $e) {
            Log::error("Error al desencriptar credenciales para proveedor {$this->provider}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Comprueba si tiene credenciales almacenadas válidas.
     */
    public function hasCredentials(): bool
    {
        return !empty($this->credentials);
    }

    public function downloadTasks(): HasMany
    {
        return $this->hasMany(OttDownloadTask::class, 'ott_account_id');
    }
}
