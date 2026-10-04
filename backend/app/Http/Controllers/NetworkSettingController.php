<?php

namespace App\Http\Controllers;

use App\Models\ServerSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NetworkSettingController extends Controller
{
    /**
     * Definición de configuraciones predeterminadas para el módulo de red (Valores según requerimiento FlixHN).
     */
    protected array $defaults = [
        'lan_networks' => '10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/16, 180.80.8.0/24',
        'local_ip_address' => '',
        'local_http_port' => 8789,
        'local_https_port' => 8920,
        'allow_remote_connections' => true,
        'remote_ip_filter' => '',
        'remote_ip_filter_mode' => 'Whitelist',
        'public_http_port' => 8789,
        'public_https_port' => 8920,
        'external_domain' => '',
        'read_proxy_headers' => 'Only when they contain remote network addresses',
        'custom_ssl_cert_path' => '',
        'certificate_password' => '',
        'secure_connection_mode' => 'Disabled',
        'enable_upnp' => false,
        'max_simultaneous_streams' => 'Unlimited',
        'internet_streaming_bitrate_limit' => '',
        'media_server_url' => 'http://45.4.87.126:6789',
        'media_server_api_key' => '',
    ];

    protected array $types = [
        'lan_networks' => 'string',
        'local_ip_address' => 'string',
        'local_http_port' => 'integer',
        'local_https_port' => 'integer',
        'allow_remote_connections' => 'boolean',
        'remote_ip_filter' => 'string',
        'remote_ip_filter_mode' => 'string',
        'public_http_port' => 'integer',
        'public_https_port' => 'integer',
        'external_domain' => 'string',
        'read_proxy_headers' => 'string',
        'custom_ssl_cert_path' => 'string',
        'certificate_password' => 'string',
        'secure_connection_mode' => 'string',
        'enable_upnp' => 'boolean',
        'max_simultaneous_streams' => 'string',
        'internet_streaming_bitrate_limit' => 'string',
        'media_server_url' => 'string',
        'media_server_api_key' => 'string',
    ];

    /**
     * Endpoint de lectura ultra-rápido de configuraciones de red.
     * GET /api/admin/network-settings o GET /api/admin/network
     * Lee DIRECTAMENTE de la base de datos local (en < 20ms) y NUNCA contacta la IP remota.
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->defaults;

            // Leer directamente de la base de datos local
            $records = ServerSetting::all();

            foreach ($records as $record) {
                if (array_key_exists($record->key, $settings)) {
                    $settings[$record->key] = $record->getCastedValue();
                }
            }

            // Fallback a configuración de media si no está definida en grupo network
            if (empty($settings['media_server_url'])) {
                $settings['media_server_url'] = ServerSetting::where('key', 'media_server_url')->value('value')
                    ?: ServerSetting::where('key', 'emby_server_url')->value('value')
                    ?: 'http://45.4.87.126:6789';
            }
            if (empty($settings['media_server_api_key'])) {
                $settings['media_server_api_key'] = ServerSetting::where('key', 'media_server_api_key')->value('value')
                    ?: ServerSetting::where('key', 'emby_api_key')->value('value')
                    ?: '';
            }

            return response()->json([
                'status' => 'success',
                'success' => true,
                'settings' => $settings,
                'data' => $settings,
            ]);
        } catch (\Throwable $e) {
            Log::error('NetworkSettingController::index Error al leer configuración: ' . $e->getMessage());
            // Si la base de datos falla temporalmente, NUNCA responder con error 500: devolver valores predeterminados
            return response()->json([
                'status' => 'success',
                'success' => true,
                'settings' => $this->defaults,
                'data' => $this->defaults,
            ]);
        }
    }

    /**
     * Alias de lectura para rutas /admin/network y /admin/network-settings
     */
    public function getSettings(): JsonResponse
    {
        return $this->index();
    }

    /**
     * Alias de lectura para métodos show/index
     */
    public function show(): JsonResponse
    {
        return $this->index();
    }

    /**
     * Validar y actualizar los parámetros de red en la base de datos local.
     * POST /api/admin/network-settings o POST /api/admin/network
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lan_networks' => 'nullable|string',
                'local_ip_address' => 'nullable|string',
                'local_http_port' => 'required|integer|min:1|max:65535',
                'local_https_port' => 'required|integer|min:1|max:65535',
                'allow_remote_connections' => 'required|boolean',
                'remote_ip_filter' => 'nullable|string',
                'remote_ip_filter_mode' => 'required|string',
                'public_http_port' => 'required|integer|min:1|max:65535',
                'public_https_port' => 'required|integer|min:1|max:65535',
                'external_domain' => 'nullable|string',
                'read_proxy_headers' => 'required|string',
                'custom_ssl_cert_path' => 'nullable|string',
                'certificate_password' => 'nullable|string',
                'secure_connection_mode' => 'required|string',
                'enable_upnp' => 'required|boolean',
                'max_simultaneous_streams' => 'required',
                'internet_streaming_bitrate_limit' => 'nullable',
                'media_server_url' => 'nullable|string',
                'media_server_api_key' => 'nullable|string',
            ]);

            foreach ($this->types as $key => $type) {
                $val = $validated[$key] ?? ($this->defaults[$key] ?? null);
                ServerSetting::set($key, $val, 'network', $type);
            }

            // Sincronizar simultáneamente con el conector de medios
            if (isset($validated['media_server_url'])) {
                $mediaUrl = rtrim(trim($validated['media_server_url']), '/');
                if (!empty($mediaUrl) && !str_starts_with($mediaUrl, 'http://') && !str_starts_with($mediaUrl, 'https://')) {
                    $mediaUrl = 'http://' . $mediaUrl;
                }
                ServerSetting::set('media_server_url', $mediaUrl, 'media', 'string');
                ServerSetting::set('emby_server_url', $mediaUrl, 'emby', 'string');
            }

            if (isset($validated['media_server_api_key'])) {
                $apiKey = trim($validated['media_server_api_key']);
                ServerSetting::set('media_server_api_key', $apiKey, 'media', 'string');
                ServerSetting::set('emby_api_key', $apiKey, 'emby', 'string');
            }

            $currentSettings = $this->index()->getData(true)['settings'] ?? $this->defaults;

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => 'Configuración de red y servidor de medios guardada exitosamente.',
                'settings' => $currentSettings,
                'data' => $currentSettings,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Errores de validación en los campos proporcionados.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('NetworkSettingController::updateSettings Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Error al guardar la configuración: ' . $e->getMessage(),
            ], 500);
        }
    }
}
