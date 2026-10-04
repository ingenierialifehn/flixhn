<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Season;
use App\Models\ServerSetting;
use App\Models\Title;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RemoteMediaConnectorService
{
    protected int $httpTimeout = 15;
    protected int $connectTimeout = 5;

    /**
     * Categorías base del sistema FlixHN para clasificar y organizar el catálogo.
     */
    protected array $defaultCategories = [
        ['name' => 'Estrenos', 'slug' => 'estrenos', 'type' => 'movie', 'sort_order' => 1],
        ['name' => 'Películas', 'slug' => 'peliculas', 'type' => 'movie', 'sort_order' => 2],
        ['name' => 'Series', 'slug' => 'series', 'type' => 'series', 'sort_order' => 3],
        ['name' => 'Animadas', 'slug' => 'animadas', 'type' => 'movie', 'sort_order' => 4],
        ['name' => 'Infantiles', 'slug' => 'infantiles', 'type' => 'mixed', 'sort_order' => 5],
        ['name' => 'Documentales', 'slug' => 'documentales', 'type' => 'mixed', 'sort_order' => 6],
        ['name' => 'Crimen', 'slug' => 'crimen', 'type' => 'mixed', 'sort_order' => 7],
        ['name' => 'Ficción', 'slug' => 'ficcion', 'type' => 'mixed', 'sort_order' => 8],
        ['name' => 'Cristianas', 'slug' => 'cristianas', 'type' => 'movie', 'sort_order' => 9],
        ['name' => 'Colecciones', 'slug' => 'colecciones', 'type' => 'mixed', 'sort_order' => 10],
    ];

    /**
     * Obtener los parámetros de conexión actuales guardados en ServerSetting.
     */
    public function getConnectionSettings(): array
    {
        $serverUrl = ServerSetting::get('media_server_url')
            ?: ServerSetting::get('emby_server_url')
            ?: 'http://45.4.87.126:6789';

        $apiKey = ServerSetting::get('media_server_api_key')
            ?: ServerSetting::get('emby_api_key')
            ?: '';

        return [
            'server_url' => rtrim($serverUrl, '/'),
            'api_key' => trim($apiKey),
        ];
    }

    /**
     * Guardar parámetros de conexión en la base de datos de FlixHN.
     */
    public function saveConnectionSettings(?string $serverUrl, ?string $apiKey): void
    {
        if (!empty($serverUrl)) {
            $formattedUrl = rtrim(trim($serverUrl), '/');
            if (!str_starts_with($formattedUrl, 'http://') && !str_starts_with($formattedUrl, 'https://')) {
                $formattedUrl = 'http://' . $formattedUrl;
            }
            ServerSetting::set('media_server_url', $formattedUrl, 'media', 'string');
            ServerSetting::set('emby_server_url', $formattedUrl, 'emby', 'string');
        }

        if ($apiKey !== null) {
            $cleanedKey = trim($apiKey);
            ServerSetting::set('media_server_api_key', $cleanedKey, 'media', 'string');
            ServerSetting::set('emby_api_key', $cleanedKey, 'emby', 'string');
        }
    }

    /**
     * Sincronizar catálogo completo desde el servidor de medios remoto.
     * Endpoint: POST /api/admin/media/sync
     *
     * @param string|null $serverUrl IP o URL del servidor (ej. http://45.4.87.126:6789)
     * @param string|null $apiKey Token o API Key de autorización
     * @return JsonResponse
     */
    public function syncCatalog(?string $serverUrl = null, ?string $apiKey = null): JsonResponse
    {
        // 1. Resolver servidor y credenciales
        $saved = $this->getConnectionSettings();
        $targetUrl = !empty($serverUrl) ? rtrim(trim($serverUrl), '/') : $saved['server_url'];
        if (!str_starts_with($targetUrl, 'http://') && !str_starts_with($targetUrl, 'https://')) {
            $targetUrl = 'http://' . $targetUrl;
        }
        $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $targetUrl);
        $targetKey = $apiKey !== null ? trim($apiKey) : $saved['api_key'];

        // Guardar configuración para futuras peticiones
        $this->saveConnectionSettings($cleanBaseUrl, $targetKey);

        // Prevenir timeout del proceso de importación
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        // Asegurar categorías base del sistema
        $this->ensureBaseCategories();

        $headers = [
            'X-Emby-Token' => $targetKey,
            'X-MediaBrowser-Token' => $targetKey,
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        $params = [
            'Recursive' => 'true',
            'IncludeItemTypes' => 'Movie,Series',
            'Fields' => 'Overview,Genres,MediaSources,ProductionYear,PremiereDate,Path,RunTimeTicks,Container',
            'api_key' => $targetKey,
        ];

        try {
            // Petición HTTP con timeout seguro para catálogos extensos
            $apiPrefix = '/emby';
            $response = Http::timeout($this->httpTimeout)
                ->connectTimeout($this->connectTimeout)
                ->withOptions(['decode_content' => true])
                ->withHeaders($headers)
                ->get($cleanBaseUrl . '/emby/Items', $params);

            // Si la URL con /emby/Items devuelve 404, ejecutar un fallback inmediato a $serverUrl . '/Items'
            if ($response->status() === 404) {
                $apiPrefix = '';
                $response = Http::timeout($this->httpTimeout)
                    ->connectTimeout($this->connectTimeout)
                    ->withOptions(['decode_content' => true])
                    ->withHeaders($headers)
                    ->get($cleanBaseUrl . '/Items', $params);
            }

            // Si la respuesta falla (HTTP != 200) responder con JSON controlado en 422
            if ($response->status() !== 200) {
                Log::warning("RemoteMediaConnector: El servidor remoto respondió con estado HTTP {$response->status()} en {$cleanBaseUrl}");
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo comunicar con el servidor remoto. Verifica que la IP y la API Key sean correctas.'
                ], 422);
            }

            $json = $response->json();
            $items = $json['Items'] ?? (is_array($json) ? $json : []);

            if (empty($items)) {
                Log::warning("RemoteMediaConnector: El servidor remoto no devolvió ítems en el catálogo.");
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo comunicar con el servidor remoto. Verifica que la IP y la API Key sean correctas.'
                ], 422);
            }

            // 2. Mapear e insertar en la base de datos local los títulos, categorías y carátulas legítimas sin duplicar registros
            $syncedCount = $this->importItems($items, $cleanBaseUrl, $apiPrefix, $targetKey);

            // 3. Asegurar que haya al menos un título destacado para el Billboard
            $this->ensureFeaturedTitle();

            // Invalidar caché del catálogo para que los clientes vean de inmediato los nuevos títulos
            Cache::forget('catalog_home_base');
            Cache::forget('catalog_home');

            $categoriesWithTitles = Category::withCount('titles')->orderBy('sort_order', 'asc')->get();

            Log::info("RemoteMediaConnector: Sincronización exitosa. {$syncedCount} títulos sincronizados desde {$cleanBaseUrl}.");

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => "Catálogo sincronizado exitosamente con el servidor. {$syncedCount} títulos sincronizados.",
                'total_synced' => $syncedCount,
                'server_url' => $cleanBaseUrl,
                'categories' => $categoriesWithTitles,
            ], 200);

        } catch (\Throwable $e) {
            Log::error("RemoteMediaConnector Error: " . $e->getMessage());
            // Si la respuesta falla o hay timeout, responder JSON controlado en 422
            return response()->json([
                'success' => false,
                'message' => 'No se pudo comunicar con el servidor remoto. Verifica que la IP y la API Key sean correctas.'
            ], 422);
        }
    }

    /**
     * Mapear e insertar ítems en la tabla `titles` y `categories` en lotes eficientes.
     */
    protected function importItems(array $items, string $serverUrl, string $prefix, ?string $apiKey): int
    {
        $categoriesBySlug = Category::all()->keyBy('slug');
        $categoriesByName = Category::all()->keyBy(fn($c) => mb_strtolower($c->name));

        $validSourceIdentifiers = [];
        $rows = [];
        $keyParam = !empty($apiKey) ? "api_key={$apiKey}" : '';
        $queryGlue = $keyParam ? "?{$keyParam}" : '';

        foreach ($items as $item) {
            $id = $item['Id'] ?? null;
            if (!$id) continue;

            $name = trim($item['Name'] ?? 'Título Sin Nombre');
            $rawType = $item['Type'] ?? 'Movie';
            $itemType = (strcasecmp($rawType, 'Series') === 0 || strcasecmp($rawType, 'TvShow') === 0) ? 'series' : 'movie';
            $overview = $item['Overview'] ?? 'Disponible en el catálogo de FlixHN.';

            // Extracción estricta del año en orden de precedencia:
            // 1. ProductionYear
            // 2. Si no existe, parsear el año de PremiereDate
            // 3. Si no existe, buscar el año entre paréntesis en el nombre/archivo (regex: /\((\d{4})\)/)
            // 4. NUNCA asignar date('Y') o el año en curso como fallback para evitar fechas falsas.
            $year = null;
            if (!empty($item['ProductionYear']) && (int) $item['ProductionYear'] > 1800 && (int) $item['ProductionYear'] <= ((int) date('Y') + 2)) {
                $year = (int) $item['ProductionYear'];
            } elseif (!empty($item['PremiereDate'])) {
                $parsedTime = strtotime($item['PremiereDate']);
                if ($parsedTime !== false) {
                    $parsedYear = (int) date('Y', $parsedTime);
                    if ($parsedYear > 1800 && $parsedYear <= ((int) date('Y') + 2)) {
                        $year = $parsedYear;
                    }
                }
            }

            if (!$year) {
                $searchStrings = [
                    $item['Path'] ?? '',
                    $item['FileName'] ?? '',
                    $name,
                ];
                if (!empty($item['MediaSources'][0]['Path'])) {
                    $searchStrings[] = $item['MediaSources'][0]['Path'];
                }
                foreach ($searchStrings as $str) {
                    if (!empty($str) && preg_match('/\((19\d{2}|20\d{2})\)/', $str, $matches)) {
                        $year = (int) $matches[1];
                        break;
                    }
                }
            }
            $dbYear = $year ?: 0;
            $durationSeconds = isset($item['RunTimeTicks']) ? (int) ($item['RunTimeTicks'] / 10000000) : ($itemType === 'movie' ? 5400 : 2700);

            // Género y Categoría
            $genres = $item['Genres'] ?? [];
            $genre = !empty($genres) ? trim($genres[0]) : ($itemType === 'movie' ? 'Película' : 'Serie');
            $catSlug = Str::slug($genre);

            $category = $categoriesBySlug->get($catSlug) ?? $categoriesByName->get(mb_strtolower($genre));
            if (!$category) {
                $category = Category::firstOrCreate(
                    ['slug' => $catSlug],
                    [
                        'name' => ucfirst($genre),
                        'type' => $itemType,
                        'folder_path' => $catSlug,
                        'sort_order' => 12,
                        'is_active' => true,
                    ]
                );
                $categoriesBySlug->put($catSlug, $category);
                $categoriesByName->put(mb_strtolower($genre), $category);
            }

            // Construir URLs legítimas del servidor de medios
            $posterUrl = "{$serverUrl}{$prefix}/Items/{$id}/Images/Primary{$queryGlue}";
            $backdropUrl = "{$serverUrl}{$prefix}/Items/{$id}/Images/Backdrop{$queryGlue}";

            // Determinar si el archivo es un contenedor soportado directamente (MP4/MKV/MOV/WEBM)
            $container = strtolower($item['Container'] ?? $item['MediaSources'][0]['Container'] ?? '');
            $itemPath = $item['Path'] ?? $item['MediaSources'][0]['Path'] ?? '';
            $mediaSourceId = $item['MediaSources'][0]['Id'] ?? $id;

            $isDirectContainer = in_array($container, ['mp4', 'm4v', 'mkv', 'mov', 'webm'])
                || preg_match('/\.(mp4|m4v|mkv|mov|webm)$/i', $itemPath);

            if ($isDirectContainer) {
                // Ruta directa de descarga/streaming nativo bit-a-bit sin transcodificación pesada
                $streamUrl = "{$serverUrl}{$prefix}/Videos/{$id}/original" . (!empty($apiKey) ? "?api_key={$apiKey}" : '');
            } else {
                // URL con parámetros obligatorios para Emby Server (DeviceId, static=true, MediaSourceId)
                $streamParams = [
                    'static' => 'true',
                    'MediaSourceId' => $mediaSourceId,
                    'DeviceId' => 'flixhn-web-client',
                ];
                if (!empty($apiKey)) {
                    $streamParams['api_key'] = $apiKey;
                }
                $streamUrl = "{$serverUrl}{$prefix}/Videos/{$id}/stream?" . http_build_query($streamParams);
            }

            $sourceIdentifier = "remote://{$id}";
            $validSourceIdentifiers[] = $sourceIdentifier;

            // Slug determinista para prevenir duplicados
            $slug = Str::slug($name) . '-' . substr(md5($id), 0, 6);

            $rows[] = [
                'name' => $name,
                'slug' => $slug,
                'type' => $itemType,
                'category_id' => $category?->id,
                'description' => $overview,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_year' => $dbYear,
                'genre' => $genre,
                'is_featured' => 0,
                'stream_path' => $streamUrl,
                'source_path' => $sourceIdentifier,
                'duration_seconds' => $durationSeconds,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Inserción en bloques de 500 registros con ON DUPLICATE KEY UPDATE por `slug`
        foreach (array_chunk($rows, 500) as $chunk) {
            Title::upsert(
                $chunk,
                ['slug'],
                [
                    'name',
                    'type',
                    'category_id',
                    'description',
                    'poster_url',
                    'backdrop_url',
                    'release_year',
                    'genre',
                    'stream_path',
                    'source_path',
                    'duration_seconds',
                    'updated_at',
                ]
            );
        }

        // Limpiar títulos huérfanos o descontinuados en el servidor remoto
        if (!empty($validSourceIdentifiers)) {
            Title::where('source_path', 'LIKE', 'remote://%')
                ->whereNotIn('source_path', $validSourceIdentifiers)
                ->delete();
        }

        return count($validSourceIdentifiers);
    }

    /**
     * Asegurar que al menos un título esté marcado como destacado para el Billboard principal.
     */
    protected function ensureFeaturedTitle(): void
    {
        $hasFeatured = Title::where('is_featured', true)->exists();
        if (!$hasFeatured) {
            $first = Title::orderBy('created_at', 'desc')->first();
            if ($first) {
                $first->update(['is_featured' => true]);
            }
        }
    }

    /**
     * Garantizar que las categorías base existan en la tabla `categories`.
     */
    protected function ensureBaseCategories(): void
    {
        foreach ($this->defaultCategories as $cat) {
            Category::firstOrCreate(
                ['slug' => $cat['slug']],
                [
                    'name' => $cat['name'],
                    'type' => $cat['type'],
                    'folder_path' => $cat['slug'],
                    'sort_order' => $cat['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Sincronizar y organizar jerárquicamente episodios y temporadas de una serie remota desde Emby.
     * Clasifica archivos con "0" (ParentIndexNumber 0 o IndexNumber 0) como Trailer / Previa.
     */
    public function syncSeriesEpisodes(Title $title, ?string $serverUrl = null, ?string $apiKey = null): array
    {
        if ($title->type !== 'series') {
            return [];
        }

        $saved = $this->getConnectionSettings();
        $targetUrl = !empty($serverUrl) ? rtrim(trim($serverUrl), '/') : $saved['server_url'];
        $cleanBaseUrl = preg_replace('#/emby/?$#i', '', $targetUrl);
        $targetKey = $apiKey !== null ? trim($apiKey) : $saved['api_key'];

        $embySeriesId = null;
        if (preg_match('#(?:remote://|emby://.*?/)([a-zA-Z0-9_-]+)#', (string)$title->source_path, $m)) {
            $embySeriesId = $m[1];
        } elseif (is_numeric($title->source_path) || preg_match('/^[a-zA-Z0-9_-]{8,}$/', (string)$title->source_path)) {
            $embySeriesId = $title->source_path;
        }

        if (!$embySeriesId) {
            return [];
        }

        $headers = [
            'Accept' => 'application/json',
            'X-Emby-Token' => $targetKey,
            'X-MediaBrowser-Token' => $targetKey,
        ];
        $params = [
            'Fields' => 'Overview,MediaSources,RunTimeTicks,ParentIndexNumber,IndexNumber,SeriesName,SeasonName,Container,Path',
            'api_key' => $targetKey,
        ];

        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders($headers)
                ->get("{$cleanBaseUrl}/emby/Shows/{$embySeriesId}/Episodes", $params);

            if ($response->status() === 404) {
                $response = Http::timeout(8)
                    ->connectTimeout(3)
                    ->withHeaders($headers)
                    ->get("{$cleanBaseUrl}/Shows/{$embySeriesId}/Episodes", $params);
            }

            if (!$response->successful()) {
                return [];
            }

            $json = $response->json();
            $items = $json['Items'] ?? (is_array($json) ? $json : []);

            if (empty($items)) {
                return [];
            }

            $trailerEpisode = null;
            $syncedEpisodes = [];

            foreach ($items as $item) {
                $epId = $item['Id'] ?? null;
                if (!$epId) continue;

                $parentIndex = isset($item['ParentIndexNumber']) ? (int) $item['ParentIndexNumber'] : 1;
                $index = isset($item['IndexNumber']) ? (int) $item['IndexNumber'] : 1;
                $epName = trim($item['Name'] ?? "Capítulo {$index}");

                // Detección de trailers o previas con "0" (Punto 4)
                $isTrailer = ($parentIndex === 0 || $index === 0 || preg_match('/^(0+|0\d+|cap[ií]tulo\s*0+|episodio\s*0+|trailer|especial)/i', $epName));

                $mediaSourceId = $item['MediaSources'][0]['Id'] ?? $epId;
                $container = strtolower($item['Container'] ?? $item['MediaSources'][0]['Container'] ?? '');
                $epPath = $item['Path'] ?? $item['MediaSources'][0]['Path'] ?? '';
                $isDirectContainer = in_array($container, ['mp4', 'm4v', 'mkv', 'mov', 'webm'])
                    || preg_match('/\.(mp4|m4v|mkv|mov|webm)$/i', $epPath);

                if ($isDirectContainer) {
                    $epStreamUrl = "{$cleanBaseUrl}/emby/Videos/{$epId}/original" . (!empty($targetKey) ? "?api_key={$targetKey}" : '');
                } else {
                    $streamParams = [
                        'static' => 'true',
                        'MediaSourceId' => $mediaSourceId,
                        'DeviceId' => 'flixhn-web-client',
                    ];
                    if (!empty($targetKey)) {
                        $streamParams['api_key'] = $targetKey;
                    }
                    $epStreamUrl = "{$cleanBaseUrl}/emby/Videos/{$epId}/stream?" . http_build_query($streamParams);
                }
                $epThumb = "{$cleanBaseUrl}/emby/Items/{$epId}/Images/Primary" . (!empty($targetKey) ? "?api_key={$targetKey}" : '');
                $duration = isset($item['RunTimeTicks']) ? (int) ($item['RunTimeTicks'] / 10000000) : 2700;

                if ($isTrailer) {
                    // Guardar como temporada 0 o episodio especial trailer
                    $seasonZero = Season::firstOrCreate(
                        ['title_id' => $title->id, 'season_number' => 0],
                        ['title' => 'Especiales / Trailers']
                    );
                    $trailerEpisode = Episode::updateOrCreate(
                        [
                            'title_id' => $title->id,
                            'season_id' => $seasonZero->id,
                            'episode_number' => 0,
                        ],
                        [
                            'title' => $epName,
                            'description' => $item['Overview'] ?? 'Trailer / Vista previa oficial.',
                            'stream_path' => $epStreamUrl,
                            'duration_seconds' => $duration,
                            'thumbnail_url' => $epThumb,
                        ]
                    );
                    continue;
                }

                // Temporadas regulares (1, 2, ...)
                $season = Season::firstOrCreate(
                    [
                        'title_id' => $title->id,
                        'season_number' => $parentIndex,
                    ],
                    [
                        'title' => "Temporada {$parentIndex}",
                    ]
                );

                $episode = Episode::updateOrCreate(
                    [
                        'title_id' => $title->id,
                        'season_id' => $season->id,
                        'episode_number' => $index,
                    ],
                    [
                        'title' => $epName,
                        'description' => $item['Overview'] ?? "Transmisión del capítulo {$index} de {$title->name}.",
                        'stream_path' => $epStreamUrl,
                        'duration_seconds' => $duration,
                        'thumbnail_url' => $epThumb,
                    ]
                );

                $syncedEpisodes[] = $episode;
            }

            return [
                'episodes' => $syncedEpisodes,
                'trailer' => $trailerEpisode,
            ];
        } catch (\Throwable $e) {
            Log::warning("RemoteMediaConnector: Error sincronizando episodios para serie {$title->id}: " . $e->getMessage());
            return [];
        }
    }
}
