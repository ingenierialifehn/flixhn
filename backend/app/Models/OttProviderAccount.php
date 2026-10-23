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
        'last_error',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'auto_download' => 'boolean',
        'session_metadata' => 'array',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $appends = [
        'last_error',
    ];

    /**
     * Mutator para last_error -> almacena en error_message de la base de datos.
     */
    public function setLastErrorAttribute($value): void
    {
        $this->attributes['error_message'] = $value;
    }

    /**
     * Accessor para last_error -> devuelve error_message.
     */
    public function getLastErrorAttribute(): ?string
    {
        return $this->attributes['error_message'] ?? null;
    }

    /**
     * Cifra un valor usando Crypt::encryptString con fallback seguro de AES-256-CBC ante APP_KEY inválida o ausente.
     */
    public static function encryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::encryptString($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException | \Exception $e) {
            $cipherKey = substr(hash('sha256', config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!'), 0, 32);
            $iv = openssl_random_pseudo_bytes(16);
            return base64_encode($iv . openssl_encrypt($value, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv));
        }
    }

    /**
     * Desencripta un valor usando Crypt::decryptString con fallback seguro de AES-256-CBC.
     */
    public static function decryptSecret(?string $payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (\Illuminate\Contracts\Encryption\DecryptException | \Exception $e) {
            try {
                $data = base64_decode($payload);
                if (strlen($data) <= 16) {
                    return $payload;
                }
                $iv = substr($data, 0, 16);
                $ciphertext = substr($data, 16);
                $cipherKey = substr(hash('sha256', config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!'), 0, 32);
                $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
                return ($decrypted !== false && $decrypted !== '') ? $decrypted : $payload;
            } catch (\Throwable $t) {
                Log::error('Error desencriptando credencial con fallback openssl: ' . $t->getMessage());
                return $payload;
            }
        }
    }

    /**
     * Encripta y almacena credenciales de acceso de forma segura.
     */
    public function setDecryptedCredentials(array $credentials): void
    {
        $this->credentials = self::encryptSecret(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Desencripta y recupera credenciales de acceso seguras para consumo de workers.
     */
    public function getDecryptedCredentials(): ?array
    {
        if (empty($this->credentials)) {
            return null;
        }

        try {
            $decrypted = self::decryptSecret($this->credentials);
            if (!$decrypted) {
                return null;
            }

            $data = json_decode($decrypted, true);
            if (!is_array($data)) {
                return null;
            }

            // Desencriptar valores sensibles si fueron cifrados individualmente
            foreach (['password', 'cookies', 'token', 'api_token'] as $field) {
                if (!empty($data[$field]) && is_string($data[$field])) {
                    $unsealed = self::decryptSecret($data[$field]);
                    if ($unsealed !== null) {
                        $data[$field] = $unsealed;
                    }
                }
            }

            return $data;
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
        if (empty($this->credentials)) {
            return false;
        }

        $decrypted = $this->getDecryptedCredentials();
        if (empty($decrypted) || !is_array($decrypted)) {
            return false;
        }

        return !empty(array_filter($decrypted, fn($val) => !empty($val) && $val !== '{}'));
    }

    public function downloadTasks(): HasMany
    {
        return $this->hasMany(OttDownloadTask::class, 'ott_account_id');
    }
}
