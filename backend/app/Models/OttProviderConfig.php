<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class OttProviderConfig extends Model
{
    use HasFactory;

    protected $table = 'ott_provider_configs';

    protected $fillable = [
        'name',
        'slug',
        'integration_type',
        'api_endpoint',
        'api_credentials',
        'oauth_config',
        'connection_status',
        'is_active',
        'playback_config',
        'timeout_seconds',
        'last_sync_at',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'playback_config' => 'array',
        'timeout_seconds' => 'integer',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Ocultar credenciales y secretos de cualquier serialización JSON.
     */
    protected $hidden = [
        'api_credentials',
        'oauth_config',
    ];

    protected $appends = [
        'is_direct_playback',
        'supported_playback_types',
        'requires_external_app',
    ];

    /**
     * Accesor para verificar si el proveedor soporta reproducción directa en FlixHN.
     */
    public function getIsDirectPlaybackAttribute(): bool
    {
        return (bool) ($this->playback_config['direct_playback'] ?? false);
    }

    /**
     * Accesor para los tipos de reproducción soportados.
     */
    public function getSupportedPlaybackTypesAttribute(): array
    {
        return $this->playback_config['supported_types'] ?? ['external_app'];
    }

    /**
     * Accesor para determinar si requiere app externa.
     */
    public function getRequiresExternalAppAttribute(): bool
    {
        return (bool) ($this->playback_config['requires_external_app'] ?? (!$this->is_direct_playback));
    }

    /**
     * Scope para proveedores activos.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para buscar por slug.
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', strtolower(trim($slug)));
    }

    /**
     * Cifra un valor confidencial con Crypt o fallback seguro AES-256-CBC.
     */
    public static function encryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::encryptString($value);
        } catch (\Throwable $e) {
            $appKey = config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!';
            $cipherKey = substr(hash('sha256', $appKey), 0, 32);
            $iv = openssl_random_pseudo_bytes(16);
            $encrypted = openssl_encrypt($value, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
            return base64_encode($iv . $encrypted);
        }
    }

    /**
     * Descifra un valor confidencial con Crypt o fallback seguro AES-256-CBC.
     */
    public static function decryptSecret(?string $payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (\Throwable $e) {
            try {
                $data = base64_decode($payload, true);
                if ($data === false || strlen($data) <= 16) {
                    return $payload;
                }
                $iv = substr($data, 0, 16);
                $ciphertext = substr($data, 16);
                $appKey = config('app.key') ?: 'flixhn-secret-salt-key-32bytes!!';
                $cipherKey = substr(hash('sha256', $appKey), 0, 32);
                $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
                return ($decrypted !== false && $decrypted !== '') ? $decrypted : $payload;
            } catch (\Throwable $t) {
                Log::error('Error desencriptando credencial de OttProviderConfig: ' . $t->getMessage());
                return null;
            }
        }
    }

    /**
     * Obtener credenciales API descifradas en arreglo asociativo.
     */
    public function getDecryptedCredentials(): array
    {
        $raw = $this->attributes['api_credentials'] ?? null;
        if (empty($raw)) {
            return [];
        }

        $decrypted = self::decryptSecret($raw);
        if (empty($decrypted)) {
            return [];
        }

        $decoded = json_decode($decrypted, true);
        return is_array($decoded) ? $decoded : ['api_key' => $decrypted];
    }

    /**
     * Almacenar credenciales API debidamente cifradas.
     */
    public function setEncryptedCredentials($value): void
    {
        if (empty($value)) {
            $this->attributes['api_credentials'] = null;
            return;
        }

        $serialized = is_array($value) ? json_encode($value) : (string) $value;
        $this->attributes['api_credentials'] = self::encryptSecret($serialized);
    }

    /**
     * Obtener configuración OAuth 2.0 descifrada.
     */
    public function getDecryptedOAuthConfig(): array
    {
        $raw = $this->attributes['oauth_config'] ?? null;
        if (empty($raw)) {
            return [];
        }

        $decrypted = self::decryptSecret($raw);
        if (empty($decrypted)) {
            return [];
        }

        $decoded = json_decode($decrypted, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Almacenar configuración OAuth 2.0 cifrada.
     */
    public function setEncryptedOAuthConfig($value): void
    {
        if (empty($value)) {
            $this->attributes['oauth_config'] = null;
            return;
        }

        $serialized = is_array($value) ? json_encode($value) : (string) $value;
        $this->attributes['oauth_config'] = self::encryptSecret($serialized);
    }

    /**
     * Registrar error de conexión y actualizar estado.
     */
    public function recordError(string $error): void
    {
        $this->update([
            'connection_status' => 'error',
            'last_error' => $error,
            'last_sync_at' => now(),
        ]);
        Log::warning("OttProviderConfig [{$this->slug}] Error de conexión: {$error}");
    }

    /**
     * Registrar sincronización exitosa y restablecer estado.
     */
    public function recordSuccess(): void
    {
        $this->update([
            'connection_status' => 'connected',
            'last_error' => null,
            'last_sync_at' => now(),
        ]);
    }

    /**
     * Activar o desactivar proveedor.
     */
    public function toggleActive(): bool
    {
        $this->is_active = !$this->is_active;
        $this->save();
        return $this->is_active;
    }

    /**
     * Reglas de validación para administración de proveedores.
     */
    public static function getValidationRules(?int $id = null): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:50|alpha_dash|unique:ott_provider_configs,slug,' . ($id ?? 'NULL') . ',id',
            'integration_type' => 'required|string|in:partner_api,deep_link,authorized_hls,internal_vod,oauth2',
            'api_endpoint' => 'nullable|url|max:500',
            'timeout_seconds' => 'nullable|integer|min:2|max:60',
            'is_active' => 'boolean',
            'playback_config' => 'nullable|array',
            'playback_config.direct_playback' => 'nullable|boolean',
            'playback_config.supported_types' => 'nullable|array',
            'playback_config.app_scheme' => 'nullable|string',
            'playback_config.web_url_template' => 'nullable|string',
        ];
    }
}
