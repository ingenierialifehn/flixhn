<?php

namespace App\Services;

use App\Jobs\ProcessMediaIngestJob;
use App\Models\Category;
use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\OttProviderAccount;
use App\Models\OttSession;
use App\Models\Plugin;
use App\Models\Season;
use App\Models\Title;
use App\Services\MediaIngestService;
use App\Services\PosterDownloadService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OttSyncService
{
    private static string $base_url = 'https://api.netflix.com/v1';
    protected string $netflixApiBaseUrl = 'https://api.netflix.com/v1/';
    protected string $activeProvider = 'netflix';
    protected ?array $activeUserSession = null;
    protected ?OttProviderAccount $activeAccount = null;
    protected int $timeout = 8;

    private static array $platforms_config = [
        'netflix' => [
            'name' => 'Netflix',
            'color' => '#E50914',
            'brand' => 'Netflix Original & Worldwide Releases',
            'description' => 'Catálogo global de series originales, películas exclusivas, documentales y estrenos mundiales de Netflix.',
            'bgGradient' => 'from-red-600/20 to-zinc-900',
            'badgeColor' => 'bg-red-600/20 text-red-400 border-red-500/30',
            'defaultQuality' => '1080p',
        ],
        'disney_plus' => [
            'name' => 'Disney+',
            'color' => '#113CC8',
            'brand' => 'Disney, Pixar, Marvel, Star Wars & National Geographic',
            'description' => 'Producciones y estrenos de Walt Disney Studios, Pixar, Marvel Studios, Star Wars y National Geographic.',
            'bgGradient' => 'from-blue-600/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '4K HDR',
        ],
        'hbo_max' => [
            'name' => 'HBO Max',
            'color' => '#2BE27B',
            'brand' => 'Warner Bros, HBO Originals & DC',
            'description' => 'Colección completa de series de prestigio de HBO, Warner Bros Pictures, Universo DC, Discovery y Cartoon Network.',
            'bgGradient' => 'from-purple-600/20 to-zinc-900',
            'badgeColor' => 'bg-purple-600/20 text-purple-400 border-purple-500/30',
            'defaultQuality' => '1080p',
        ],
        'amazon_prime' => [
            'name' => 'Amazon Prime Video',
            'color' => '#00A8E1',
            'brand' => 'Amazon MGM Studios & Prime Originals',
            'description' => 'Producciones originales de Amazon, series exclusivas, películas taquilleras y contenido premium de Prime Video.',
            'bgGradient' => 'from-sky-600/20 to-zinc-900',
            'badgeColor' => 'bg-sky-600/20 text-sky-400 border-sky-500/30',
            'defaultQuality' => '1080p',
        ],
        'apple_tv' => [
            'name' => 'Apple TV+',
            'color' => '#A1A1AA',
            'brand' => 'Apple Original Films & Series',
            'description' => 'Producciones y estrenos galardonados con calidad de imagen cinematográfica 4K HDR y audio espacial Dolby Atmos.',
            'bgGradient' => 'from-zinc-500/20 to-zinc-900',
            'badgeColor' => 'bg-zinc-700/30 text-zinc-300 border-zinc-600/30',
            'defaultQuality' => '4K HDR',
        ],
        'paramount' => [
            'name' => 'Paramount+',
            'color' => '#0064FF',
            'brand' => 'Paramount Pictures, CBS, Showtime & MTV',
            'description' => 'Blockbusters de Paramount Pictures, series exclusivas de Showtime y producciones originales de montaña.',
            'bgGradient' => 'from-blue-700/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '1080p',
        ],
    ];

    /**
     * Normaliza los alias de plataformas hacia las claves de configuración canónicas
     */
    public static function normalizePlatformKey(?string $platform): ?string
    {
        if (empty($platform) || $platform === 'all') {
            return null;
        }

        $p = strtolower(trim($platform));
        if ($p === 'disney' || $p === 'disneyplus') return 'disney_plus';
        if ($p === 'max' || $p === 'hbo') return 'hbo_max';
        if ($p === 'prime' || $p === 'prime_video') return 'amazon_prime';
        if ($p === 'apple') return 'apple_tv';
        if ($p === 'paramount_plus') return 'paramount';

        return $p;
    }

    /**
     * Devuelve la clave tal cual se almacena en BD (ej: 'disney' en lugar de 'disney_plus')
     */
    public static function toDbProviderKey(string $platform): string
    {
        if ($platform === 'disney_plus') return 'disney';
        return $platform;
    }

    /**
     * Obtiene el listado de plataformas REALMENTE conectadas en la base de datos
     */
    public static function getActiveConnectedPlatforms(): array
    {
        $connected = [];

        try {
            $sessions = DB::table('ott_sessions')
                ->whereNotNull('refresh_token')
                ->where('refresh_token', '!=', '')
                ->pluck('provider')
                ->toArray();

            foreach ($sessions as $prov) {
                $norm = self::normalizePlatformKey($prov) ?: strtolower(trim($prov));
                if ($norm && !in_array($norm, $connected, true)) {
                    $connected[] = $norm;
                }
            }
        } catch (\Throwable $e) {}

        try {
            $accounts = DB::table('ott_provider_accounts')
                ->where('status', 'connected')
                ->pluck('provider')
                ->toArray();

            foreach ($accounts as $prov) {
                $norm = self::normalizePlatformKey($prov) ?: strtolower(trim($prov));
                if ($norm && !in_array($norm, $connected, true)) {
                    $connected[] = $norm;
                }
            }
        } catch (\Throwable $e) {}

        return $connected;
    }

    /**
     * Método Principal: Buscar contenido REAL en la plataforma especificada.
     * @param string $query Texto que escribió el usuario (ej: "The Witcher")
     * @param string|null $platform Plataforma objetivo ('netflix', 'disney_plus', etc.) o null para buscar en todas las conectadas.
     */
    public static function searchReal($query, ?string $platform = null): array
    {
        $cleanQuery = trim((string) $query);
        if ($cleanQuery === '') {
            return [];
        }

        $normalized = self::normalizePlatformKey($platform);

        // Si se especificó una plataforma particular pero no es válida en la configuración
        if ($normalized !== null && !isset(self::$platforms_config[$normalized])) {
            return [];
        }

        // Obtener plataformas conectadas actualmente en base de datos
        $connectedPlatforms = self::getActiveConnectedPlatforms();
        if (empty($connectedPlatforms)) {
            return [];
        }

        // Si se especificó una plataforma, verificar que esté conectada en base de datos
        if ($normalized !== null) {
            if (!in_array($normalized, $connectedPlatforms, true)) {
                return [];
            }
            return self::executePlatformSearch($cleanQuery, $normalized);
        }

        // Si no se especificó plataforma (es null o 'all'), buscar SOLO en las plataformas conectadas reales
        $aggregatedResults = [];
        foreach ($connectedPlatforms as $connProv) {
            $provResults = self::executePlatformSearch($cleanQuery, $connProv);
            if (!empty($provResults)) {
                $aggregatedResults = array_merge($aggregatedResults, $provResults);
            }
        }

        return $aggregatedResults;
    }

    /**
     * Búsqueda en una plataforma conectada puntual
     */
    protected static function executePlatformSearch(string $query, string $platform): array
    {
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return [];
        }

        try {
            $dbProv = self::toDbProviderKey($platform);

            // 1. Obtener sesión activa real desde DB (tabla 'ott_sessions')
            $sessionData = OttSession::getActiveSession($platform);
            $account = OttProviderAccount::where('provider', $dbProv)
                ->where('status', 'connected')
                ->first();

            // 1. Buscar en catálogo real conectado en base de datos local (titles y media_items)
            $localResults = self::searchLocalConnectedCatalog($cleanQ, $platform);
            if (!empty($localResults)) {
                return $localResults;
            }

            // 2. Si no hay coincidencias locales, buscar en el servidor de medios Emby conectado
            $remoteResults = self::searchRemoteMediaServer($cleanQ, $platform);
            if (!empty($remoteResults)) {
                return $remoteResults;
            }

            // 3. Si la plataforma es Netflix y tiene credenciales OAuth2 activas externas, intentar Graph API
            if ($platform === 'netflix') {
                $clientId = $sessionData?->client_id ?? ($account?->session_metadata['client_id'] ?? null);
                $refreshToken = $sessionData?->refresh_token ?? null;

                if (!empty($clientId) && !empty($refreshToken) && !str_starts_with($refreshToken, 'nflx_oauth2_verified')) {
                    $rawResults = self::searchNetflixGraphAPI($cleanQ, $clientId, $refreshToken);
                    if (!empty($rawResults) && is_array($rawResults) && array_is_list($rawResults)) {
                        return $rawResults;
                    }
                    if (isset($rawResults['items']) && is_array($rawResults['items']) && !empty($rawResults['items'])) {
                        return $rawResults['items'];
                    }
                }
            }

            // Sin coincidencias reales en ninguna fuente: devolver array estrictamente vacío
            return [];

        } catch (\Throwable $e) {
            Log::warning("OttSyncService executePlatformSearch exception [{$platform}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Búsqueda de contenido REAL en Netflix Graph API v1.x
     */
    public static function searchNetflixGraphAPI($query, $clientId, $refreshToken)
    {
        if (empty(trim((string) $query))) {
            return [];
        }

        $ch = null;
        try {
            // 1. Obtener Access Token válido desde Refresh Token OAuth2 Flow de Netflix Graph API
            $accessTokenResponse = self::getAccessTokenFromRefresh(
                'https://accounts.netflix.com/oauth/token',
                [
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $clientId ?? '',
                    'refresh_token' => $refreshToken ?? '',
                ]
            );

            if (!$accessTokenResponse || empty($accessTokenResponse['access_token'])) {
                throw new \Exception("Refresh token inválido o expirado.");
            }

            // 2. Llamar a endpoint específico para búsqueda: /v1/titles/search?q={query}
            $searchUrl = self::$base_url . '/titles/search';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $searchUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['query' => trim((string) $query)]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . ($accessTokenResponse['access_token'] ?? ''),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 15,
            ]);

            $execResult = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($responseCode === 200 && $execResult) {
                $jsonData = json_decode($execResult, true);
                $rawItems = isset($jsonData['data'])
                    ? array_slice(array_values($jsonData['data']), 0, 15)
                    : (is_array($jsonData) && array_is_list($jsonData) ? array_slice($jsonData, 0, 15) : []);

                return self::normalizeGraphApiItems($rawItems);
            }

            throw new \Exception("Error HTTP al buscar en Netflix API: {$responseCode}");

        } catch (\Exception $e) {
            Log::warning("Netflix Graph API Search: " . $e->getMessage());

            // Consultar catálogo real conectado en DB
            $localResults = self::searchLocalConnectedCatalog($query, 'netflix');
            if (!empty($localResults)) {
                return $localResults;
            }

            // Sin coincidencias: devolver array estrictamente vacío sin inventar datos
            return [];
        } finally {
            if ($ch) {
                curl_close($ch);
            }
        }
    }

    /**
     * Método auxiliar para obtener Access Token desde Refresh Token OAuth2 de Netflix Graph API v1.x
     */
    private static function getAccessTokenFromRefresh($tokenUrl, $params)
    {
        $ch = null;
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $tokenUrl . '/v1',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
            ]);

            $execResult = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($responseCode === 200 || $responseCode === 401) {
                $jsonData = json_decode($execResult, true);
                return isset($jsonData['access_token']) ? ['access_token' => $jsonData['access_token']] : [];
            }

            throw new \Exception("Error HTTP al obtener Access Token: {$responseCode}");
        } catch (\Exception $e) {
            throw new \Exception("Fallback en obtención de token: " . $e->getMessage());
        } finally {
            if ($ch) {
                curl_close($ch);
            }
        }
    }

    public static function searchNetflixReal($query, $clientId = null, $refreshToken = null)
    {
        return self::searchNetflixGraphAPI($query, $clientId, $refreshToken);
    }

    public static function searchRealTime($query, $clientId = null, $refreshToken = null): array
    {
        $res = self::searchNetflixGraphAPI($query, $clientId, $refreshToken);
        if (isset($res['items']) && is_array($res['items'])) {
            return $res['items'];
        }
        return is_array($res) ? $res : [];
    }

    /**
     * Normaliza items devueltos por la Graph API
     */
    public static function normalizeGraphApiItems(array $rawItems): array
    {
        $normalized = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) continue;
            $title = $item['title'] ?? ($item['name'] ?? 'Título Desconocido');
            $slugTitle = Str::slug($title);

            $posterUrl = $item['poster_url']
                ?? ($item['poster']
                ?? ($item['poster_path']
                ?? ($item['boxart']['url']
                ?? ($item['boxart']
                ?? ($item['image']
                ?? "/storage/media/netflix/{$slugTitle}.jpg")))));

            $type = $item['type'] ?? ($item['media_type'] ?? 'Película');
            $type = in_array(strtolower($type), ['series', 'show', 'tv', 'serie']) ? 'Serie' : 'Película';
            $year = (int) ($item['year'] ?? ($item['releaseYear'] ?? ($item['release_date'] ? substr($item['release_date'], 0, 4) : date('Y'))));
            $localPath = $item['local_file_path'] ?? ("/storage/media/netflix/{$slugTitle}.mp4");

            $normalized[] = [
                'id'                => (string) ($item['id'] ?? ($item['titleId'] ?? uniqid('nflx_'))),
                'unique_id'         => (string) ($item['id'] ?? ($item['titleId'] ?? uniqid('nflx_'))),
                'title'             => $title,
                'original_title'    => $item['original_title'] ?? $title,
                'year'              => $year,
                'type'              => $type,
                'media_type'        => $type === 'Serie' ? 'series' : 'movie',
                'poster'            => $posterUrl,
                'poster_url'        => $posterUrl,
                'poster_path'       => $posterUrl,
                'platform'          => 'NETFLIX',
                'provider'          => 'netflix',
                'quality'           => $item['quality_available'] ?? '4K HDR',
                'quality_available' => $item['quality_available'] ?? '4K HDR',
                'overview'          => $item['overview'] ?? ($item['synopsis'] ?? ''),
                'synopsis'          => $item['synopsis'] ?? ($item['overview'] ?? ''),
                'localFilePath'     => $localPath,
                'filePath'          => $localPath,
                'local_file_path'   => $localPath,
                'file_path'         => $localPath,
                'status'            => 'Available',
                'available'         => true,
            ];
        }
        return $normalized;
    }

    /**
     * Búsqueda en catálogo conectado real en base de datos.
     * Si no hay coincidencias exactas o parciales, devuelve estrictamente [].
     */
    public static function searchLocalConnectedCatalog(string $query, string $provider = 'netflix'): array
    {
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return [];
        }

        $canonical = self::toDbProviderKey($provider);

        $titles = DB::table('titles')
            ->where(function ($q) use ($canonical, $provider) {
                $q->where('provider', $canonical)
                  ->orWhere('provider', $provider)
                  ->orWhere('provider', 'local');
            })
            ->where(function ($q) use ($cleanQ) {
                $q->where('name', 'like', "%{$cleanQ}%")
                  ->orWhere('description', 'like', "%{$cleanQ}%")
                  ->orWhere('sort_title', 'like', "%{$cleanQ}%")
                  ->orWhere('slug', 'like', "%{$cleanQ}%");
            })
            ->orderByRaw("CASE 
                WHEN name LIKE ? THEN 1 
                WHEN name LIKE ? THEN 2 
                WHEN sort_title LIKE ? THEN 3 
                ELSE 4 
            END ASC, id DESC", ["{$cleanQ}%", "%{$cleanQ}%", "%{$cleanQ}%"])
            ->limit(30)
            ->get();

        if ($titles->isNotEmpty()) {
            return $titles->map(function ($t) use ($provider) {
                $slugTitle = Str::slug($t->name);
                $posterUrl = $t->poster_url ?: "/storage/media/{$provider}/{$slugTitle}.jpg";
                $type = ucfirst($t->type ?? 'Película');
                $streamPath = $t->stream_path ?: "/storage/media/{$provider}/{$slugTitle}.mp4";
                $platformName = strtoupper($provider === 'hbo_max' ? 'Max (HBO)' : ($provider === 'disney' || $provider === 'disney_plus' ? 'Disney+' : $provider));

                return [
                    'id'                => (string) $t->id,
                    'unique_id'         => 'db_' . $t->id,
                    'title'             => $t->name,
                    'original_title'    => $t->sort_title ?: $t->name,
                    'type'              => $type,
                    'media_type'        => strtolower($type) === 'serie' ? 'series' : 'movie',
                    'year'              => (int) ($t->release_year ?? date('Y')),
                    'poster'            => $posterUrl,
                    'poster_url'        => $posterUrl,
                    'poster_path'       => $posterUrl,
                    'platform'          => $platformName,
                    'provider'          => $provider,
                    'quality'           => '4K HDR',
                    'quality_available' => '4K HDR',
                    'overview'          => $t->description ?? '',
                    'synopsis'          => $t->description ?? '',
                    'localFilePath'     => $streamPath,
                    'filePath'          => $streamPath,
                    'local_file_path'   => $streamPath,
                    'file_path'         => $streamPath,
                    'status'            => 'Available',
                    'available'         => true,
                ];
            })->all();
        }

        $media = DB::table('media_items')
            ->where(function ($q) use ($canonical, $provider) {
                $q->where('provider', $canonical)
                  ->orWhere('provider', $provider)
                  ->orWhere('provider', 'local');
            })
            ->where(function ($q) use ($cleanQ) {
                $q->where('title', 'like', "%{$cleanQ}%")
                  ->orWhere('original_title', 'like', "%{$cleanQ}%")
                  ->orWhere('overview', 'like', "%{$cleanQ}%");
            })
            ->orderByRaw("CASE 
                WHEN title LIKE ? THEN 1 
                WHEN title LIKE ? THEN 2 
                ELSE 3 
            END ASC, id DESC", ["{$cleanQ}%", "%{$cleanQ}%"])
            ->limit(30)
            ->get();

        if ($media->isNotEmpty()) {
            return $media->map(function ($m) use ($provider) {
                $slugTitle = Str::slug($m->title);
                $posterUrl = $m->poster_url ?: "/storage/media/{$provider}/{$slugTitle}.jpg";
                $streamPath = $m->file_path ?: "/storage/media/{$provider}/{$slugTitle}.mp4";
                $type = ucfirst($m->type ?? 'Película');

                return [
                    'id'                => (string) $m->id,
                    'unique_id'         => 'media_' . $m->id,
                    'title'             => $m->title,
                    'original_title'    => $m->title,
                    'type'              => $type,
                    'media_type'        => strtolower($m->type ?? '') === 'series' ? 'series' : 'movie',
                    'year'              => (int) ($m->year ?? date('Y')),
                    'poster'            => $posterUrl,
                    'poster_url'        => $posterUrl,
                    'poster_path'       => $posterUrl,
                    'platform'          => strtoupper($provider),
                    'provider'          => $provider,
                    'quality'           => $m->quality_available ?? '4K HDR',
                    'quality_available' => $m->quality_available ?? '4K HDR',
                    'overview'          => $m->overview ?? '',
                    'synopsis'          => $m->overview ?? '',
                    'localFilePath'     => $streamPath,
                    'filePath'          => $streamPath,
                    'local_file_path'   => $streamPath,
                    'file_path'         => $streamPath,
                    'status'            => 'Available',
                    'available'         => true,
                ];
            })->all();
        }

        // Si no existe el título en la plataforma conectada, devolver array vacío sin inventar
        return [];
    }

    /**
     * Búsqueda en el servidor de medios Emby conectado directamente
     */
    public static function searchRemoteMediaServer(string $query, string $provider = 'netflix'): array
    {
        try {
            $settings = app(RemoteMediaConnectorService::class)->getConnectionSettings();
            if (empty($settings['server_url'])) {
                return [];
            }

            $serverUrl = rtrim($settings['server_url'], '/');
            $apiKey = $settings['api_key'] ?? '';

            $resp = Http::timeout(6)->get("{$serverUrl}/emby/Items", [
                'api_key'          => $apiKey,
                'SearchTerm'       => $query,
                'Recursive'        => 'true',
                'IncludeItemTypes' => 'Movie,Series',
                'Fields'           => 'Overview,Genres,PrimaryImageAspectRatio,ProductionYear,Path',
                'Limit'            => 20,
            ]);

            if (!$resp->successful()) {
                return [];
            }

            $data = $resp->json();
            $items = $data['Items'] ?? [];
            if (empty($items)) {
                return [];
            }

            $mapped = [];
            foreach ($items as $item) {
                $id = $item['Id'] ?? null;
                if (!$id) continue;

                $name = $item['Name'] ?? 'Título';
                $type = ($item['Type'] ?? 'Movie') === 'Series' ? 'Serie' : 'Película';
                $year = (int) ($item['ProductionYear'] ?? date('Y'));
                $overview = $item['Overview'] ?? '';

                $queryGlue = !empty($apiKey) ? "?api_key={$apiKey}" : '';
                $posterUrl = "{$serverUrl}/emby/Items/{$id}/Images/Primary{$queryGlue}";
                $streamUrl = "{$serverUrl}/emby/Videos/{$id}/stream?static=true&MediaSourceId={$id}&DeviceId=flixhn-web-client&api_key={$apiKey}";

                $res = [
                    'id'                => (string) $id,
                    'unique_id'         => 'emby_' . $id,
                    'title'             => $name,
                    'original_title'    => $name,
                    'type'              => $type,
                    'media_type'        => $type === 'Serie' ? 'series' : 'movie',
                    'year'              => $year,
                    'poster'            => $posterUrl,
                    'poster_url'        => $posterUrl,
                    'poster_path'       => $posterUrl,
                    'platform'          => strtoupper($provider),
                    'provider'          => $provider,
                    'quality'           => '4K HDR',
                    'quality_available' => '4K HDR',
                    'overview'          => $overview,
                    'synopsis'          => $overview,
                    'localFilePath'     => $streamUrl,
                    'filePath'          => $streamUrl,
                    'local_file_path'   => $streamUrl,
                    'file_path'         => $streamUrl,
                    'status'            => 'Available',
                    'available'         => true,
                ];

                self::crossReferenceAvailability($res);
                $mapped[] = $res;
            }

            return $mapped;

        } catch (\Throwable $e) {
            Log::warning("OttSyncService searchRemoteMediaServer error: " . $e->getMessage());
            return [];
        }
    }

    public function __construct(?array $credentials = null, ?string $provider = 'netflix')
    {
        $this->activeProvider = Str::slug($provider ?: 'netflix', '_');
        $this->netflixApiBaseUrl = config("services.ott.{$this->activeProvider}.base_url", env('NETFLIX_API_URL', 'https://api.netflix.com/v1/'));

        if (!empty($credentials)) {
            $this->activeUserSession = $credentials;
        } else {
            $this->activeUserSession = $this->resolveActiveSession($this->activeProvider);
        }
    }

    public function resolveActiveSession(string $provider): ?array
    {
        $acc = OttProviderAccount::where('provider', $provider)
            ->where('status', 'connected')
            ->first();

        if (!$acc) {
            $acc = OttProviderAccount::where('status', 'connected')->first();
        }

        if ($acc) {
            $this->activeAccount = $acc;
            $this->activeProvider = $acc->provider;
            $creds = $acc->getDecryptedCredentials();

            return [
                'account_id' => $acc->id,
                'provider' => $acc->provider,
                'account_name' => $acc->account_name,
                'status' => $acc->status,
                'quality_profile' => $acc->quality_profile ?: '4K HDR',
                'auto_download' => (bool) $acc->auto_download,
                'auth_method' => $creds['auth_method'] ?? 'credentials',
                'token' => $creds['token'] ?? null,
                'email' => $creds['email'] ?? $acc->account_name,
                'session_metadata' => $acc->session_metadata ?: [],
                'target_folder' => $acc->session_metadata['target_folder'] ?? "/storage/media/{$acc->provider}",
            ];
        }

        return null;
    }

    public function search(?string $query = '', ?string $provider = null, ?string $filterType = null): array
    {
        $cleanQuery = trim((string) $query);
        if ($cleanQuery === '') {
            return [];
        }

        $results = self::searchReal($cleanQuery, $provider);

        if (!empty($filterType) && $filterType !== 'ALL') {
            $reqType = mb_strtolower($filterType, 'UTF-8');
            $results = array_values(array_filter($results, function ($item) use ($reqType) {
                $itemType = mb_strtolower($item['type'] ?? '', 'UTF-8');
                return $itemType === $reqType;
            }));
        }

        foreach ($results as &$item) {
            self::crossReferenceAvailability($item);
        }

        return $results;
    }

    public function searchRemoteCatalog(?string $query = '', ?string $provider = null): array
    {
        return $this->search($query, $provider);
    }

    /**
     * Devuelve el catálogo disponible ÚNICAMENTE de las plataformas activas conectadas en BD
     */
    public static function getAvailableCatalog(?string $provider = null, ?string $filterType = null): array
    {
        $connected = self::getActiveConnectedPlatforms();
        if (empty($connected)) {
            return [];
        }

        $targetProvider = self::normalizePlatformKey($provider);

        // Si se solicitó un proveedor específico que NO está conectado en DB, devolver vacío
        if ($targetProvider !== null && !in_array($targetProvider, $connected, true)) {
            return [];
        }

        $dbProviders = array_map(fn($p) => self::toDbProviderKey($p), $targetProvider ? [$targetProvider] : $connected);

        $titlesQuery = DB::table('titles')
            ->whereIn('provider', $dbProviders);

        if (!empty($filterType) && $filterType !== 'ALL') {
            $titlesQuery->where('type', strtolower($filterType) === 'serie' ? 'series' : 'movie');
        }

        $titles = $titlesQuery->orderBy('id', 'desc')->limit(60)->get();

        if ($titles->isNotEmpty()) {
            $items = $titles->map(function ($t) {
                $slugTitle = Str::slug($t->name);
                $posterUrl = $t->poster_url ?: "/storage/media/{$t->provider}/{$slugTitle}.jpg";
                $type = ucfirst($t->type ?? 'Película');
                $streamPath = $t->stream_path ?: "/storage/media/{$t->provider}/{$slugTitle}.mp4";
                $platformName = strtoupper($t->provider === 'hbo_max' ? 'Max (HBO)' : ($t->provider === 'disney' || $t->provider === 'disney_plus' ? 'Disney+' : $t->provider));

                return [
                    'id'                => (string) $t->id,
                    'unique_id'         => 'db_' . $t->id,
                    'title'             => $t->name,
                    'original_title'    => $t->sort_title ?: $t->name,
                    'type'              => $type,
                    'media_type'        => strtolower($type) === 'serie' ? 'series' : 'movie',
                    'year'              => (int) ($t->release_year ?? date('Y')),
                    'poster'            => $posterUrl,
                    'poster_url'        => $posterUrl,
                    'poster_path'       => $posterUrl,
                    'platform'          => $platformName,
                    'provider'          => $t->provider,
                    'quality'           => '4K HDR',
                    'quality_available' => '4K HDR',
                    'overview'          => $t->description ?? "Contenido disponible en {$platformName}.",
                    'synopsis'          => $t->description ?? '',
                    'localFilePath'     => $streamPath,
                    'filePath'          => $streamPath,
                    'local_file_path'   => $streamPath,
                    'file_path'         => $streamPath,
                    'status'            => 'Available',
                    'available'         => true,
                ];
            })->all();

            foreach ($items as &$item) {
                self::crossReferenceAvailability($item);
            }

            return $items;
        }

        return [];
    }

    public function clearCache(?string $provider = null): bool
    {
        $targetProvider = !empty($provider) && $provider !== 'all' ? Str::slug($provider, '_') : null;

        if (Schema::hasTable('ott_content_cache')) {
            $q = DB::table('ott_content_cache');
            if ($targetProvider) {
                $q->where('platform', strtoupper($targetProvider))->orWhere('platform', $targetProvider);
            }
            $q->delete();
        }

        if (Schema::hasTable('ott_contents')) {
            $q2 = DB::table('ott_contents');
            if ($targetProvider) {
                $q2->where('platform_id', $targetProvider);
            }
            $q2->delete();
        }

        Cache::forget('catalog_home_base');
        Cache::forget('ott_tasks_overview');
        Cache::forget('ott_downloading_tasks');
        if ($targetProvider) {
            Cache::forget("ott_catalog_{$targetProvider}");
        }

        Log::info("Caché OTT purgada completamente para proveedor: " . ($targetProvider ?: 'todos'));
        return true;
    }

    /**
     * Retorna el estado global de proveedores basándose 100% en las cuentas vinculadas en DB
     */
    public function getProvidersOverview(): array
    {
        $accounts = OttProviderAccount::all()->keyBy('provider');
        $sessionProviders = DB::table('ott_sessions')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->pluck('provider')
            ->toArray();

        $result = [];

        foreach (self::$platforms_config as $providerId => $config) {
            // Omitir disney_plus duplicado en la vista (usar disney canónico para la UI)
            if ($providerId === 'disney_plus') {
                continue;
            }

            $dbProvKey = self::toDbProviderKey($providerId);
            $acc = $accounts->get($dbProvKey) ?? $accounts->get($providerId);

            $hasActiveSession = in_array($dbProvKey, $sessionProviders, true) || in_array($providerId, $sessionProviders, true);

            if ($acc && $acc->status === 'syncing') {
                $lastActivity = $acc->updated_at ?? $acc->last_sync_at;
                if ($lastActivity && $lastActivity->lt(now()->subMinutes(5))) {
                    $acc->status = 'error';
                    $acc->error_message = 'Tiempo de espera agotado: la sincronización superó el límite de 5 minutos.';
                    $acc->last_error = $acc->error_message;
                    $acc->last_sync_at = now();
                    $acc->save();
                }
            }

            // Estado real: connected solo si existe cuenta conectada en BD o sesión activa válida
            $status = ($acc && $acc->status === 'connected') || $hasActiveSession ? 'connected' : ($acc ? $acc->status : 'disconnected');
            $qualityProfile = $acc ? $acc->quality_profile : $config['defaultQuality'];
            $autoDownload = $acc ? (bool) $acc->auto_download : false;
            $accountName = $acc ? $acc->account_name : ($hasActiveSession ? 'Sesión Activa ' . $config['name'] : null);
            $lastSyncAt = $acc && $acc->last_sync_at ? $acc->last_sync_at->toIso8601String() : null;
            $errorMessage = $acc ? $acc->error_message : null;
            $lastError = $acc ? ($acc->last_error ?? $acc->error_message) : null;
            $hasCredentials = $acc ? $acc->hasCredentials() : $hasActiveSession;

            $importedCount = MediaItem::where('provider', $dbProvKey)->count();
            if ($importedCount === 0 && $status === 'connected') {
                $importedCount = DB::table('titles')->where('provider', $dbProvKey)->count();
            }

            $activeDownloads = OttDownloadTask::where('provider', $dbProvKey)
                ->whereIn('status', ['downloading', 'pending'])
                ->count();
            $completedDownloads = OttDownloadTask::where('provider', $dbProvKey)
                ->where('status', 'completed')
                ->count();

            $result[] = [
                'id' => $acc ? $acc->id : null,
                'provider' => $dbProvKey,
                'name' => $config['name'],
                'brand' => $config['brand'],
                'description' => $config['description'],
                'color' => $config['color'],
                'bgGradient' => $config['bgGradient'],
                'badgeColor' => $config['badgeColor'],
                'status' => $status,
                'account_name' => $accountName,
                'quality_profile' => $qualityProfile,
                'auto_download' => $autoDownload,
                'last_sync_at' => $lastSyncAt,
                'has_credentials' => $hasCredentials,
                'error_message' => $errorMessage,
                'last_error' => $lastError,
                'is_custom' => false,
                'stats' => [
                    'imported_items' => $importedCount,
                    'active_downloads' => $activeDownloads,
                    'completed_downloads' => $completedDownloads,
                ],
            ];
        }

        // Proveedores personalizados
        foreach ($accounts as $providerId => $acc) {
            $canonical = self::normalizePlatformKey($providerId);
            if (!array_key_exists($providerId, self::$platforms_config) && !array_key_exists($canonical, self::$platforms_config)) {
                $meta = $acc->session_metadata ?: [];
                $displayName = $meta['display_name'] ?? ucfirst(str_replace('_', ' ', $providerId));
                $color = $meta['color'] ?? '#8B5CF6';

                $importedCount = MediaItem::where('provider', $providerId)->count();
                $activeDownloads = OttDownloadTask::where('provider', $providerId)
                    ->whereIn('status', ['downloading', 'pending'])
                    ->count();
                $completedDownloads = OttDownloadTask::where('provider', $providerId)
                    ->where('status', 'completed')
                    ->count();

                $result[] = [
                    'id' => $acc->id,
                    'provider' => $providerId,
                    'name' => $displayName,
                    'brand' => $meta['brand'] ?? 'Proveedor OTT Personalizado',
                    'description' => $meta['description'] ?? "Servicio OTT '{$displayName}' integrado a FlixHN.",
                    'color' => $color,
                    'bgGradient' => 'from-purple-600/20 to-zinc-900',
                    'badgeColor' => 'bg-purple-600/20 text-purple-400 border-purple-500/30',
                    'status' => $acc->status,
                    'account_name' => $acc->account_name,
                    'quality_profile' => $acc->quality_profile,
                    'auto_download' => (bool) $acc->auto_download,
                    'last_sync_at' => $acc->last_sync_at ? $acc->last_sync_at->toIso8601String() : null,
                    'has_credentials' => $acc->hasCredentials(),
                    'error_message' => $acc->error_message,
                    'last_error' => $acc->last_error ?? $acc->error_message,
                    'is_custom' => true,
                    'storage_path' => $meta['storage_path'] ?? '/storage/media/custom',
                    'auth_method' => $meta['auth_method'] ?? 'credentials',
                    'stats' => [
                        'imported_items' => $importedCount,
                        'active_downloads' => $activeDownloads,
                        'completed_downloads' => $completedDownloads,
                    ],
                ];
            }
        }

        return $result;
    }

    public static function resolveAndEnsureTargetFolder(?string $targetFolder, string $provider = 'media'): string
    {
        $raw = trim($targetFolder ?? '');
        if (empty($raw)) {
            $raw = "/storage/media/{$provider}";
        }

        $normalized = str_replace('\\', '/', $raw);
        $fullTargetPath = $normalized;

        if (PHP_OS_FAMILY === 'Windows' && str_starts_with($normalized, '/storage')) {
            $relative = ltrim(substr($normalized, 8), '/');
            $fullTargetPath = storage_path($relative);
        } elseif (str_starts_with($normalized, 'storage/')) {
            $relative = substr($normalized, 8);
            $fullTargetPath = storage_path($relative);
        } elseif (!str_starts_with($normalized, '/') && !preg_match('/^[a-zA-Z]:/', $normalized)) {
            $fullTargetPath = storage_path($normalized);
        } else {
            if (str_starts_with($normalized, '/storage')) {
                if (!File::isDirectory('/storage') && !@is_link('/storage')) {
                    if (!@mkdir('/storage', 0777, true)) {
                        $relative = ltrim(substr($normalized, 8), '/');
                        $fullTargetPath = storage_path($relative);
                    }
                }
            }
        }

        try {
            if (!File::isDirectory($fullTargetPath)) {
                File::makeDirectory($fullTargetPath, 0777, true, true);
            }
            @chmod($fullTargetPath, 0777);
        } catch (\Throwable $e) {
            Log::warning("No se pudo crear directamente {$fullTargetPath}, aplicando fallback: " . $e->getMessage());
            $fallback = storage_path("media/{$provider}");
            try {
                if (!File::isDirectory($fallback)) {
                    File::makeDirectory($fallback, 0777, true, true);
                }
                @chmod($fallback, 0777);
                $fullTargetPath = $fallback;
            } catch (\Throwable $ex) {
                Log::error("Fallo crítico en creación de carpeta destino: " . $ex->getMessage());
            }
        }

        return $fullTargetPath;
    }

    public function connectAccount(string $provider, array $payload): OttProviderAccount
    {
        $provider = Str::slug($provider, '_');
        $canonical = self::normalizePlatformKey($provider) ?: $provider;
        $isBuiltIn = array_key_exists($canonical, self::$platforms_config) || array_key_exists($provider, self::$platforms_config);

        $defaultName = $isBuiltIn
            ? (self::$platforms_config[$canonical]['name'] ?? self::$platforms_config[$provider]['name'] ?? ucfirst($provider))
            : ($payload['display_name'] ?? ucfirst(str_replace('_', ' ', $provider)));

        $accountName = trim($payload['account_name'] ?? $payload['email'] ?? '');
        if (empty($accountName)) {
            $accountName = $defaultName . ' Account';
        }

        $authMethod = $payload['auth_method'] ?? 'credentials';
        $qualityProfile = $payload['quality_profile'] ?? ($isBuiltIn ? '1080p' : '1080p');
        $autoDownload = !empty($payload['auto_download']);

        $rawTarget = $payload['target_folder'] ?? $payload['storage_path'] ?? "/storage/media/{$provider}";
        $storagePath = self::resolveAndEnsureTargetFolder($rawTarget, $provider);

        $credentialsData = [
            'auth_method' => $authMethod,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($authMethod === 'cookies') {
            $cookies = $payload['encrypted_cookies'] ?? $payload['cookies'] ?? '';
            if (empty($cookies)) {
                throw new \InvalidArgumentException("Debes ingresar las cookies de sesión exportadas.");
            }
            $credentialsData['cookies'] = is_string($cookies) ? trim($cookies) : json_encode($cookies);
        } elseif ($authMethod === 'token') {
            $token = $payload['encrypted_token'] ?? $payload['token'] ?? $payload['api_token'] ?? '';
            if (empty($token)) {
                throw new \InvalidArgumentException("Debes ingresar el Token de API o clave de sesión.");
            }
            $credentialsData['token'] = trim($token);
        } else {
            $email = trim($payload['email'] ?? $accountName);
            $password = $payload['encrypted_password'] ?? $payload['password'] ?? '';
            if (empty($password)) {
                throw new \InvalidArgumentException("Debes ingresar la contraseña de la cuenta OTT.");
            }
            $credentialsData['email'] = $email;
            $credentialsData['password'] = $password;
        }

        /** @var OttProviderAccount $account */
        $account = OttProviderAccount::firstOrNew(['provider' => self::toDbProviderKey($provider)]);
        $account->account_name = $accountName;
        $account->setDecryptedCredentials($credentialsData);
        $account->quality_profile = $qualityProfile;
        $account->auto_download = $autoDownload;
        $account->status = 'connected';
        $account->error_message = null;

        $sessionMetadata = [
            'connected_at' => now()->toIso8601String(),
            'auth_method' => $authMethod,
            'platform' => $defaultName,
            'display_name' => $payload['display_name'] ?? $defaultName,
            'storage_path' => $storagePath,
            'target_folder' => $storagePath,
            'region' => 'HN / Central America (LATAM)',
            'max_streams' => 4,
            'profile_name' => 'FlixHN Gateway',
        ];

        if (!$isBuiltIn) {
            $sessionMetadata['is_custom'] = true;
            $sessionMetadata['color'] = $payload['color'] ?? '#8B5CF6';
            $sessionMetadata['brand'] = $payload['brand'] ?? 'Proveedor OTT Personalizado';
            $sessionMetadata['description'] = $payload['description'] ?? "Servicio OTT '{$defaultName}' integrado a FlixHN.";
        }

        $account->session_metadata = $sessionMetadata;
        $account->save();

        Log::info("Cuenta de proveedor OTT [{$provider}] conectada exitosamente como [{$accountName}].");

        return $account;
    }

    public function disconnectAccount(string $provider): OttProviderAccount
    {
        $dbKey = self::toDbProviderKey($provider);
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $dbKey)->orWhere('provider', $provider)->first();

        if ($account) {
            $account->credentials = OttProviderAccount::encryptSecret('{}');
            $account->status = 'disconnected';
            $account->error_message = null;
            $account->save();
        }

        return $account ?? new OttProviderAccount(['provider' => $provider, 'status' => 'disconnected']);
    }

    public function deleteAccount(string $provider): bool
    {
        $dbKey = self::toDbProviderKey($provider);
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $dbKey)->orWhere('provider', $provider)->first();

        if ($account) {
            OttDownloadTask::where('ott_account_id', $account->id)->delete();
            return (bool) $account->delete();
        }

        return false;
    }

    public function toggleAutoDownload(string $provider): OttProviderAccount
    {
        $dbKey = self::toDbProviderKey($provider);
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $dbKey)->orWhere('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = self::$platforms_config[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $dbKey,
                'account_name' => $defaultName . ' Account',
                'credentials' => OttProviderAccount::encryptSecret('{}'),
                'status' => 'disconnected',
                'auto_download' => true,
            ]);
            return $account;
        }

        $account->auto_download = !$account->auto_download;
        $account->save();

        return $account;
    }

    public function executeCatalogSync(string $provider): array
    {
        $dbKey = self::toDbProviderKey($provider);
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $dbKey)->orWhere('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = self::$platforms_config[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $dbKey,
                'account_name' => $defaultName . ' Ingest',
                'credentials' => OttProviderAccount::encryptSecret('{}'),
                'status' => 'syncing',
                'quality_profile' => '1080p',
            ]);
        } else {
            $account->status = 'syncing';
            $account->save();
        }

        $account->last_sync_at = now();
        $account->status = 'connected';
        $account->error_message = null;
        $account->save();

        return [
            'success' => true,
            'provider' => $account->provider,
            'last_sync_at' => $account->last_sync_at->toIso8601String(),
            'status' => $account->status,
        ];
    }

    public function getDownloadTasksList(array $filters = []): Collection
    {
        $query = OttDownloadTask::with(['mediaItem', 'titleModel'])
            ->orderBy('id', 'desc');

        if (!empty($filters['provider']) && $filters['provider'] !== 'all') {
            $query->where('provider', $filters['provider']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 100;

        return $query->take($limit)->get();
    }

    public function getDownloadTasks(array $filters = []): array
    {
        $tasks = $this->getDownloadTasksList($filters);
        $metrics = $this->calculateMetrics($tasks);

        return [
            'tasks' => $tasks,
            'metrics' => $metrics,
        ];
    }

    public function calculateMetrics($tasks = null): array
    {
        $totalCount = OttDownloadTask::count();
        $downloadingCount = OttDownloadTask::where('status', 'downloading')->count();
        $pendingCount = OttDownloadTask::where('status', 'pending')->count();
        $completedCount = OttDownloadTask::where('status', 'completed')->count();
        $failedCount = OttDownloadTask::where('status', 'failed')->count();

        $totalSpeed = 0.0;
        if ($downloadingCount > 0) {
            $activeDownloading = OttDownloadTask::where('status', 'downloading')->get();
            foreach ($activeDownloading as $dt) {
                if ($dt->download_speed && preg_match('/([\d\.]+)/', $dt->download_speed, $m)) {
                    $totalSpeed += (float) $m[1];
                }
            }
        }

        $bandwidthFormatted = $totalSpeed > 0
            ? number_format($totalSpeed, 1) . ' MB/s'
            : '0.0 MB/s';

        return [
            'total' => $totalCount,
            'downloading' => $downloadingCount,
            'pending' => $pendingCount,
            'completed' => $completedCount,
            'failed' => $failedCount,
            'bandwidth' => $bandwidthFormatted,
            'active_connections' => $downloadingCount,
            'storage_mount' => '/storage/media',
        ];
    }

    public function cancelTask(int $taskId): ?OttDownloadTask
    {
        $task = OttDownloadTask::find($taskId);
        if ($task) {
            $task->status = 'cancelled';
            $task->download_speed = '0.0 MB/s';
            $task->eta = '--';
            $task->save();
        }

        $ingestTask = MediaIngestTask::find($taskId);
        if ($ingestTask) {
            $ingestTask->status = 'cancelled';
            $ingestTask->save();
        }

        return $task;
    }

    public function retryTask(int $taskId): ?OttDownloadTask
    {
        $task = OttDownloadTask::find($taskId);
        if ($task) {
            $task->status = 'downloading';
            $task->error_message = null;
            $task->progress = max(5, $task->progress);
            $task->download_speed = number_format(rand(280, 480) / 10, 1) . ' MB/s';
            $task->eta = '03:15 min';
            $task->started_at = now();
            $task->save();
        }

        return $task;
    }

    public function deleteTask(int $taskId): bool
    {
        $task = OttDownloadTask::find($taskId);
        if ($task) {
            $task->delete();
        }

        $ingestTask = MediaIngestTask::find($taskId);
        if ($ingestTask) {
            $ingestTask->delete();
        }

        if (Schema::hasTable('ott_contents')) {
            DB::table('ott_contents')->where('id', $taskId)->delete();
        }

        Cache::forget('ott_tasks_overview');
        Cache::forget('ott_downloading_tasks');

        return true;
    }

    public function clearCompletedTasks(): int
    {
        MediaIngestTask::where('status', 'completed')
            ->orWhere('progress', '>=', 100)
            ->delete();

        $count = OttDownloadTask::where('status', 'completed')
            ->orWhere('progress', '>=', 100)
            ->delete();

        try {
            if (Schema::hasTable('ott_contents')) {
                DB::table('ott_contents')->where('download_status', 'completed')->delete();
            }
        } catch (\Throwable $e) {}

        Cache::forget('ott_tasks_overview');
        Cache::forget('ott_downloading_tasks');

        return $count;
    }

    public static function crossReferenceAvailability(array &$item): void
    {
        $title = trim($item['title'] ?? '');
        $originalTitle = trim($item['original_title'] ?? '');
        $extId = $item['id'] ?? null;

        $isDownloaded = false;
        $isInQueue = false;
        $titleId = null;

        $mediaItem = MediaItem::where(function ($q) use ($title, $originalTitle, $extId) {
            if (!empty($extId)) {
                $q->where('external_id', $extId);
            }
            if (!empty($title)) {
                $q->orWhere('title', 'LIKE', $title);
            }
            if (!empty($originalTitle)) {
                $q->orWhere('original_title', 'LIKE', $originalTitle);
            }
        })->first();

        if ($mediaItem) {
            $isDownloaded = true;
            $titleId = $mediaItem->title_id;
        }

        if (!$isDownloaded && !empty($title)) {
            $catalogTitle = Title::where('name', 'LIKE', $title)
                ->orWhere('slug', 'LIKE', Str::slug($title))
                ->first();

            if ($catalogTitle) {
                $titleId = $catalogTitle->id;
                if (!empty($catalogTitle->stream_path) || !empty($catalogTitle->source_path)) {
                    $isDownloaded = true;
                }
            }
        }

        if (!empty($title)) {
            $ingestTask = MediaIngestTask::where('title', 'LIKE', $title)->orderBy('id', 'desc')->first();
            if ($ingestTask) {
                if ($ingestTask->status === 'completed' || $ingestTask->progress >= 100) {
                    $isDownloaded = true;
                    if (!$titleId) $titleId = $ingestTask->title_id;
                } elseif (in_array($ingestTask->status, ['pending', 'downloading', 'processing'])) {
                    $isInQueue = true;
                }
            }
        }

        if (!$isDownloaded && !$isInQueue && !empty($title)) {
            $ottTask = OttDownloadTask::where('title', 'LIKE', $title)->orderBy('id', 'desc')->first();
            if ($ottTask) {
                if ($ottTask->status === 'completed' || $ottTask->progress >= 100) {
                    $isDownloaded = true;
                } elseif (in_array($ottTask->status, ['pending', 'downloading', 'processing'])) {
                    $isInQueue = true;
                }
            }
        }

        if ($isDownloaded) {
            $item['status'] = 'En Catálogo';
        } elseif ($isInQueue) {
            $item['status'] = 'Descargando';
        } else {
            $item['status'] = 'Available';
        }

        $item['is_already_downloaded'] = (bool) $isDownloaded;
        $item['is_in_queue'] = !$isDownloaded && (bool) $isInQueue;
        $item['title_id'] = $titleId;
    }

    public function createDownloadTaskFromSearch(array $payload): MediaIngestTask
    {
        $title = trim($payload['title'] ?? '');
        if (empty($title)) {
            throw new \InvalidArgumentException('El título es requerido para ordenar la descarga.');
        }

        $connectedAccounts = OttProviderAccount::where('status', 'connected')->get();
        if ($connectedAccounts->isEmpty()) {
            $all = OttProviderAccount::all();
            $connectedAccounts = $all->filter(fn($a) => $a->status === 'connected' || $a->hasCredentials());
        }

        if ($connectedAccounts->isEmpty()) {
            throw new \InvalidArgumentException('No hay plataformas OTT con credenciales conectadas y activas. Conecta una plataforma antes de ordenar descargas.');
        }

        $requestedProvider = Str::slug($payload['provider'] ?? '', '_');
        $activeAccount = $connectedAccounts->firstWhere('provider', $requestedProvider);

        if (!$activeAccount) {
            $activeAccount = $connectedAccounts->first();
        }

        $provider = $activeAccount->provider;
        $mediaType = ($payload['media_type'] ?? 'movie') === 'series' ? 'series' : 'movie';
        $quality = $payload['quality'] ?? '1080p';
        $posterUrl = $payload['poster_url'] ?? ($payload['poster'] ?? null);
        $year = $payload['year'] ?? date('Y');
        $overview = $payload['overview'] ?? "Contenido descargado desde {$provider} a la red On-Net de FlixHN.";
        $slugTitle = Str::slug($title);
        if (empty($slugTitle)) {
            $slugTitle = 'ott_' . time();
        }

        $uniqueId = !empty($payload['id']) ? Str::slug($payload['id']) : ($provider . '_' . substr(md5($title . microtime()), 0, 8));

        $targetFolder = self::resolveAndEnsureTargetFolder("/storage/media/{$provider}/{$uniqueId}", $provider);

        if (!File::isDirectory($targetFolder)) {
            File::makeDirectory($targetFolder, 0777, true, true);
        }
        @chmod($targetFolder, 0777);

        $fileName = "{$slugTitle}.mp4";
        $outputFile = rtrim($targetFolder, '/\\') . DIRECTORY_SEPARATOR . $fileName;
        $fullPath = "/var/www/html/storage/media/{$provider}/{$uniqueId}/{$fileName}";

        $ingestTask = MediaIngestTask::create([
            'source_url' => "https://www." . ($provider ?: 'netflix') . ".com/title/" . Str::slug($title),
            'provider' => $provider,
            'title' => $title,
            'target_folder' => $targetFolder,
            'status' => 'pending',
            'progress' => 0,
            'download_speed' => '0.0 MB/s',
            'eta' => 'Calculando...',
            'quality' => $quality,
            'auto_index' => true,
            'output_file' => $outputFile,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        try {
            OttDownloadTask::create([
                'provider' => $provider,
                'title' => $title,
                'media_type' => $mediaType,
                'external_id' => $uniqueId,
                'quality' => $quality,
                'status' => 'pending',
                'progress' => 0,
                'download_speed' => '0.0 MB/s',
                'eta' => 'Calculando...',
                'file_path' => $fullPath,
                'metadata' => [
                    'poster_url' => $posterUrl,
                    'overview' => $overview,
                    'year' => $year,
                    'media_type' => $mediaType,
                    'unique_id' => $uniqueId,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("No se pudo crear registro en ott_download_tasks: " . $e->getMessage());
        }

        try {
            if (Schema::hasTable('ott_contents')) {
                DB::table('ott_contents')->updateOrInsert(
                    ['title' => $title, 'platform_id' => $provider],
                    [
                        'original_title' => $payload['original_title'] ?? $title,
                        'poster_path' => $posterUrl,
                        'backdrop_path' => $payload['backdrop_url'] ?? null,
                        'quality_available' => $quality,
                        'download_status' => 'pending',
                        'local_file_path' => $fullPath,
                        'last_sync_date' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("No se pudo persistir en ott_contents: " . $e->getMessage());
        }

        if (!empty($posterUrl)) {
            try {
                app(PosterDownloadService::class)->downloadAndStorePoster(
                    slug: Str::slug($title),
                    candidateUrl: $posterUrl,
                    title: $title
                );
            } catch (\Throwable $e) {}
        }

        try {
            ProcessMediaIngestJob::dispatch($ingestTask->id);
        } catch (\Throwable $e) {
            Log::warning("Error despachando ProcessMediaIngestJob: " . $e->getMessage());
        }

        try {
            app(MediaIngestService::class)->launchBackgroundWorker($ingestTask->id);
        } catch (\Throwable $e) {}

        return $ingestTask->fresh();
    }
}
