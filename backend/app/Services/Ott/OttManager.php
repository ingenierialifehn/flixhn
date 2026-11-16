<?php

namespace App\Services\Ott;

use App\Models\OttProviderConfig;
use App\Services\Ott\Contracts\OttProviderInterface;
use App\Services\Ott\Adapters\LocalVodAdapter;
use App\Services\Ott\Adapters\AuthorizedHlsAdapter;
use App\Services\Ott\Adapters\NetflixAdapter;
use App\Services\Ott\Adapters\DisneyPlusAdapter;
use App\Services\Ott\Adapters\MaxAdapter;
use App\Services\Ott\Adapters\GenericPartnerOttAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OttManager
{
    /**
     * Instancia de adaptadores en memoria caché durante el ciclo de vida del request.
     */
    protected array $adapters = [];

    /**
     * Resuelve el adaptador correspondiente para un proveedor dado.
     */
    public function resolveAdapter(string $slug): ?OttProviderInterface
    {
        $normalizedSlug = strtolower(trim($slug));

        if (isset($this->adapters[$normalizedSlug])) {
            return $this->adapters[$normalizedSlug];
        }

        // Consultar configuración desde base de datos
        $config = OttProviderConfig::bySlug($normalizedSlug)->first();

        if (!$config) {
            $names = [
                'prime' => 'Amazon Prime Video',
                'amazon' => 'Amazon Prime Video',
                'apple' => 'Apple TV+',
                'appletv' => 'Apple TV+',
                'paramount' => 'Paramount+',
                'paramountplus' => 'Paramount+',
                'starplus' => 'Star+',
                'crunchyroll' => 'Crunchyroll',
            ];
            $friendlyName = $names[$normalizedSlug] ?? ucfirst($normalizedSlug);
            $appScheme = match ($normalizedSlug) {
                'prime', 'amazon' => 'primevideo://',
                'apple', 'appletv' => 'appletv://',
                'paramount', 'paramountplus' => 'paramountplus://',
                'crunchyroll' => 'crunchyroll://',
                default => "{$normalizedSlug}://",
            };
            $webUrl = match ($normalizedSlug) {
                'prime', 'amazon' => 'https://www.primevideo.com',
                'apple', 'appletv' => 'https://tv.apple.com',
                'paramount', 'paramountplus' => 'https://www.paramountplus.com',
                'crunchyroll' => 'https://www.crunchyroll.com',
                default => "https://www.{$normalizedSlug}.com",
            };

            $config = new OttProviderConfig([
                'slug' => $normalizedSlug,
                'name' => $friendlyName,
                'is_active' => true,
                'integration_type' => 'app_redirect',
                'playback_config' => [
                    'direct_playback' => false,
                    'app_scheme' => $appScheme,
                    'web_url_template' => $webUrl,
                ]
            ]);
        }

        // Mapeo de adaptadores especializados o genéricos
        $adapter = match ($normalizedSlug) {
            'local' => new LocalVodAdapter($config),
            'partner_hls' => new AuthorizedHlsAdapter($config),
            'netflix' => new NetflixAdapter($config),
            'disney', 'disneyplus', 'disney_plus' => new DisneyPlusAdapter($config),
            'max', 'hbo_max', 'hbo' => new MaxAdapter($config),
            default => new GenericPartnerOttAdapter($config),
        };

        if ($adapter) {
            $this->adapters[$normalizedSlug] = $adapter;
        }

        return $adapter;
    }

    /**
     * Obtiene todos los adaptadores activos habilitados.
     *
     * @return OttProviderInterface[]
     */
    public function getActiveAdapters(): array
    {
        $activeConfigs = OttProviderConfig::active()->get();
        $adapters = [];

        // Asegurar que el catálogo local siempre esté disponible
        $hasLocal = false;

        foreach ($activeConfigs as $config) {
            $adapter = $this->resolveAdapter($config->slug);
            if ($adapter) {
                $adapters[$config->slug] = $adapter;
                if ($config->slug === 'local') {
                    $hasLocal = true;
                }
            }
        }

        if (!$hasLocal) {
            $adapters['local'] = new LocalVodAdapter(null);
        }

        return $adapters;
    }

    /**
     * Búsqueda unificada en catálogo local y proveedores externos compatibles.
     *
     * @param string $query Término de búsqueda
     * @param array $options Filtros opcionales (type, provider, limit)
     * @return array Resultados unificados, desduplicados y normalizados
     */
    public function unifiedSearch(string $query, array $options = []): array
    {
        $cleanQuery = trim($query);
        if (mb_strlen($cleanQuery) < 1) {
            return [
                'query' => '',
                'total' => 0,
                'results' => [],
            ];
        }

        $specificProvider = $options['provider'] ?? null;
        $adaptersToQuery = [];

        if (!empty($specificProvider) && $specificProvider !== 'all') {
            $single = $this->resolveAdapter($specificProvider);
            if ($single) {
                $adaptersToQuery[$specificProvider] = $single;
            }
        } else {
            $adaptersToQuery = $this->getActiveAdapters();
        }

        $allRawResults = [];

        // 1. Consultar proveedores activos con aislamiento de errores
        foreach ($adaptersToQuery as $slug => $adapter) {
            try {
                $results = $adapter->search($cleanQuery, $options);
                foreach ($results as $item) {
                    $allRawResults[] = $item;
                }
            } catch (\Throwable $e) {
                Log::warning("Error en búsqueda OTT para proveedor [{$slug}]: " . $e->getMessage());
            }
        }

        // 2. Unificar y Desduplicar resultados
        $deduplicated = $this->deduplicateAndNormalize($allRawResults);

        return [
            'query' => $cleanQuery,
            'total' => count($deduplicated),
            'results' => $deduplicated,
        ];
    }

    /**
     * Desduplica y normaliza títulos considerando coincidencia de nombre y año.
     */
    protected function deduplicateAndNormalize(array $items): array
    {
        $seen = [];
        $final = [];

        // Función de normalización alfanumérica sin acentos
        $normalize = function (string $str): string {
            $str = mb_strtolower(trim($str));
            $map = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u'];
            $str = strtr($str, $map);
            return preg_replace('/[^a-z0-9]/', '', $str) ?: '';
        };

        // Orden de preferencia: local > partner_hls > netflix > disney > max > otros
        $priorityOrder = ['local' => 10, 'partner_hls' => 8, 'netflix' => 6, 'disney' => 5, 'max' => 4];

        usort($items, function ($a, $b) use ($priorityOrder) {
            $pA = $priorityOrder[$a['provider'] ?? ''] ?? 1;
            $pB = $priorityOrder[$b['provider'] ?? ''] ?? 1;
            return $pB <=> $pA;
        });

        foreach ($items as $item) {
            $normTitle = $normalize($item['title'] ?? '');
            $year = (int) ($item['release_year'] ?? 0);
            $type = $item['type'] ?? 'movie';

            // Clave única compuesta por título normalizado + tipo
            $key = "{$normTitle}_{$type}";

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $final[] = $item;
            }
        }

        return $final;
    }

    /**
     * Resuelve el enlace y modo de reproducción para un contenido OTT.
     *
     * @param string $contentId Identificador del contenido
     * @param string|null $providerSlug Slug del proveedor
     * @param array $context Contexto adicional (IP, usuario, tipo)
     * @return array Respuesta de reproducción estructurada
     */
    public function resolveStreamLink(string $contentId, ?string $providerSlug = null, array $context = []): array
    {
        // Inferir el proveedor si el contentId viene con prefijo identificador
        if (empty($providerSlug)) {
            if (str_starts_with($contentId, 'nflx_')) {
                $providerSlug = 'netflix';
            } elseif (str_starts_with($contentId, 'disney_') || str_starts_with($contentId, 'disneyplus_')) {
                $providerSlug = 'disney';
            } elseif (str_starts_with($contentId, 'max_') || str_starts_with($contentId, 'hbo_')) {
                $providerSlug = 'max';
            } elseif (str_starts_with($contentId, 'hls_')) {
                $providerSlug = 'partner_hls';
            } else {
                $providerSlug = 'local';
            }
        }

        $adapter = $this->resolveAdapter($providerSlug);

        if (!$adapter) {
            return [
                'success' => false,
                'content_id' => $contentId,
                'provider' => $providerSlug,
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => "El proveedor [{$providerSlug}] no está registrado o no cuenta con adaptador habilitado.",
            ];
        }

        // Verificar si el proveedor está activo en la base de datos
        $config = OttProviderConfig::bySlug($adapter->getSlug())->first();
        if ($config && !$config->is_active) {
            return [
                'success' => false,
                'content_id' => $contentId,
                'provider' => $adapter->getSlug(),
                'provider_name' => $adapter->getName(),
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => "El proveedor [{$adapter->getName()}] se encuentra temporalmente desactivado por el administrador.",
            ];
        }

        try {
            return $adapter->getStreamLink($contentId, $context);
        } catch (\Throwable $e) {
            Log::error("Error resolviendo stream-link para [{$contentId}] con proveedor [{$providerSlug}]: " . $e->getMessage());
            return [
                'success' => false,
                'content_id' => $contentId,
                'provider' => $providerSlug,
                'playback_type' => 'OTT-UNAVAILABLE',
                'action' => 'unavailable',
                'message' => 'Error interno al procesar el enlace de reproducción: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Resumen completo de proveedores configurados sin exponer secretos ni credenciales.
     */
    public function getProvidersOverview(): array
    {
        $configs = OttProviderConfig::orderBy('id', 'asc')->get();

        return $configs->map(function (OttProviderConfig $cfg) {
            return [
                'id' => $cfg->id,
                'name' => $cfg->name,
                'slug' => $cfg->slug,
                'integration_type' => $cfg->integration_type,
                'api_endpoint' => $cfg->api_endpoint,
                'connection_status' => $cfg->connection_status,
                'is_active' => $cfg->is_active,
                'direct_playback' => $cfg->is_direct_playback,
                'supported_playback_types' => $cfg->supported_playback_types,
                'requires_external_app' => $cfg->requires_external_app,
                'timeout_seconds' => $cfg->timeout_seconds,
                'last_sync_at' => $cfg->last_sync_at?->toIso8601String(),
                'last_error' => $cfg->last_error,
            ];
        })->toArray();
    }

    /**
     * Comprueba la salud de un proveedor y actualiza la telemetría en base de datos.
     */
    public function checkProviderStatus(int|string $idOrSlug): array
    {
        $config = is_numeric($idOrSlug)
            ? OttProviderConfig::find((int)$idOrSlug)
            : OttProviderConfig::bySlug((string)$idOrSlug)->first();

        if (!$config) {
            return [
                'success' => false,
                'status' => 'not_found',
                'message' => "Configuración del proveedor [{$idOrSlug}] no encontrada.",
            ];
        }

        $adapter = $this->resolveAdapter($config->slug);
        if (!$adapter) {
            $config->recordError("No existe adaptador de software para el slug: {$config->slug}");
            return [
                'success' => false,
                'status' => 'error',
                'message' => "Adaptador no encontrado para {$config->slug}",
            ];
        }

        $health = $adapter->checkHealth();
        if ($health['success']) {
            $config->recordSuccess();
        } else {
            $config->recordError($health['message'] ?? 'Fallo en la prueba de conexión');
        }

        return array_merge($health, [
            'provider_id' => $config->id,
            'slug' => $config->slug,
            'name' => $config->name,
            'is_active' => $config->is_active,
        ]);
    }
}
