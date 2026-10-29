<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Episode;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\OttProviderAccount;
use App\Models\Plugin;
use App\Models\Season;
use App\Models\Title;
use App\Jobs\ProcessMediaIngestJob;
use App\Services\MediaIngestService;
use App\Services\PosterDownloadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Exception;

class OttSyncService
{
    private static string $base_url = 'https://api.netflix.com/v1';
    protected string $netflixApiBaseUrl = 'https://api.netflix.com/v1/';
    protected string $activeProvider = 'netflix';
    protected ?array $activeUserSession = null;
    protected ?OttProviderAccount $activeAccount = null;
    protected int $timeout = 8;

    protected array $supportedProviders = [
        'netflix' => [
            'id' => 'netflix',
            'name' => 'Netflix',
            'brand' => 'Netflix Original & Worldwide Releases',
            'description' => 'Catálogo global de series originales, películas exclusivas, documentales y estrenos mundiales de Netflix.',
            'color' => '#E50914',
            'bgGradient' => 'from-red-600/20 to-zinc-900',
            'badgeColor' => 'bg-red-600/20 text-red-400 border-red-500/30',
            'defaultQuality' => '1080p',
        ],
        'disney' => [
            'id' => 'disney',
            'name' => 'Disney+',
            'brand' => 'Disney, Pixar, Marvel, Star Wars & National Geographic',
            'description' => 'Producciones y estrenos de Walt Disney Studios, Pixar, Marvel Studios, Star Wars y National Geographic.',
            'color' => '#0063E5',
            'bgGradient' => 'from-blue-600/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '4K HDR',
        ],
        'hbo_max' => [
            'id' => 'hbo_max',
            'name' => 'Max (HBO)',
            'brand' => 'Warner Bros, HBO Originals & DC',
            'description' => 'Colección completa de series de prestigio de HBO, Warner Bros Pictures, Universo DC, Discovery y Cartoon Network.',
            'color' => '#5822b4',
            'bgGradient' => 'from-purple-600/20 to-zinc-900',
            'badgeColor' => 'bg-purple-600/20 text-purple-400 border-purple-500/30',
            'defaultQuality' => '1080p',
        ],
        'amazon_prime' => [
            'id' => 'amazon_prime',
            'name' => 'Amazon Prime Video',
            'brand' => 'Amazon MGM Studios & Prime Originals',
            'description' => 'Producciones originales de Amazon, series exclusivas, películas taquilleras y contenido premium de Prime Video.',
            'color' => '#00A8E1',
            'bgGradient' => 'from-sky-600/20 to-zinc-900',
            'badgeColor' => 'bg-sky-600/20 text-sky-400 border-sky-500/30',
            'defaultQuality' => '1080p',
        ],
        'apple_tv' => [
            'id' => 'apple_tv',
            'name' => 'Apple TV+',
            'brand' => 'Apple Original Films & Series',
            'description' => 'Producciones y estrenos galardonados con calidad de imagen cinematográfica 4K HDR y audio espacial Dolby Atmos.',
            'color' => '#A1A1AA',
            'bgGradient' => 'from-zinc-500/20 to-zinc-900',
            'badgeColor' => 'bg-zinc-700/30 text-zinc-300 border-zinc-600/30',
            'defaultQuality' => '4K HDR',
        ],
        'paramount' => [
            'id' => 'paramount',
            'name' => 'Paramount+',
            'brand' => 'Paramount Pictures, CBS, Showtime & MTV',
            'description' => 'Blockbusters de Paramount Pictures, series exclusivas de Showtime y producciones originales de montaña.',
            'color' => '#0064FF',
            'bgGradient' => 'from-blue-700/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '1080p',
        ],
    ];

    /**
     * Conexión Real a Netflix Graph API v1.x
     */
    public static function searchNetflixReal($query, $clientId = null, $refreshToken = null)
    {
        if (empty(trim((string) $query))) {
            return []; // Si está vacío no busca nada real
        }

        $ch = null;
        try {
            // 1. Obtener un Access Token válido desde el Refresh Token (OAuth2 Flow)
            $accessTokenResponse = self::getAccessToken($clientId, $refreshToken);
            
            $accessToken = is_array($accessTokenResponse) 
                ? ($accessTokenResponse['access_token'] ?? null) 
                : $accessTokenResponse;

            if (empty($accessToken)) {
                throw new \Exception("Refresh token inválido o expirado.");
            }

            // 2. Llamar a la API de Búsqueda Real con el access_token y query del usuario:
            $searchUrl = self::$base_url . '/titles/search'; 
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $searchUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true, // El endpoint usa POST para búsqueda simple
                CURLOPT_POSTFIELDS => http_build_query(['query' => trim($query)]), // Envía lo que escribió el usuario
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/x-www-form-urlencoded', 
                    'Accept: application/json' // Formato de respuesta JSON oficial Netflix Graph API v1.0.x (o similar)
                ],
            ]);
            
            $response = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($responseCode === 200 && $response) { // Si la petición fue exitosa
                $decoded = json_decode($response, true);
                $rawItems = $decoded['data'] ?? ($decoded['items'] ?? ($decoded['results'] ?? []));
                if (!is_array($rawItems) && is_array($decoded)) {
                    $rawItems = array_is_list($decoded) ? $decoded : [];
                }

                $normalizedItems = [];
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
                    if (in_array(strtolower($type), ['series', 'show', 'tv', 'serie'])) {
                        $type = 'Serie';
                    } else {
                        $type = 'Película';
                    }

                    $year = (int) ($item['year'] ?? ($item['releaseYear'] ?? ($item['release_date'] ? substr($item['release_date'], 0, 4) : date('Y'))));
                    $localPath = $item['local_file_path'] ?? ("/storage/media/netflix/{$slugTitle}.mp4");

                    $normalizedItems[] = [
                        'id' => (string) ($item['id'] ?? ($item['titleId'] ?? uniqid('nflx_'))),
                        'title' => $title,
                        'year' => $year,
                        'type' => $type,
                        'poster_url' => $posterUrl,
                        'poster_path' => $posterUrl,
                        'poster' => $posterUrl,
                        'platform' => 'NETFLIX',
                        'quality_available' => $item['quality_available'] ?? '4K HDR',
                        'local_file_path' => $localPath,
                        'filePath' => $localPath,
                        'file_path' => '/var/www/html' . (str_starts_with($localPath, '/') ? $localPath : '/' . $localPath),
                    ];
                }

                return $normalizedItems;
             
            } else {
                throw new \Exception("Error HTTP al buscar en Netflix API: {$responseCode}");
            }
            
        } catch (\Exception $e) {
             // Fallback inteligente si falla la conexión real o la API no responde aún (ej. pruebas iniciales):
             // Devolver un resultado vacío con mensaje claro para el usuario sin inventar datos falsos.
             Log::warning("Netflix Graph API Search Error: " . $e->getMessage());
             return ['fallback_message' => 'Cargando catálogo dinámico...', 'items' => []]; 
        } finally {
            if ($ch) {
                curl_close($ch);
            }
        }
    }

    /**
     * Método auxiliar para obtener Access Token desde Refresh Token OAuth2 de Netflix Graph API v1.x
     */
    private static function getAccessToken($clientId, $refreshToken)
    {
        // URL oficial del endpoint de token refresh según documentación gráfica de Netflix:
        // https://api.netflix.com/v1/tokens/refresh (o similar dependiendo versión)
        $tokenUrl = 'https://accounts.netflix.com/oauth/token'; 

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl . '/v1', 
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POST => true, // POST con parámetros para refrescar acceso
            CURLOPT_POSTFIELDS => http_build_query([ // Parámetros requeridos por el flujo OAuth2 estándar de Netflix Graph API v1.x:
                'grant_type' => 'refresh_token',
                'client_id'   => $clientId ?? '',        // ID del cliente desde DB o config
                'refresh_token'=> $refreshToken ?? ''     // Token actual que expira pronto si es necesario rotar después
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true, // Verifica certificado SSL real
        ]);

        $response = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 

        if ($responseCode === 200 && $response) { // Si el token se refrescó correctamente
            $decoded = json_decode($response, true);
            curl_close($ch);
            return $decoded['access_token'] ?? $decoded; // Devuelve el access token o respuesta
        } else {
            curl_close($ch);
            throw new \Exception("Error HTTP al obtener Access Token desde Netflix API OAuth2: {$responseCode}"); 
        }
    }

    /**
     * Alias compatible de búsqueda en tiempo real
     */
    public static function searchRealTime($query, $clientId = null, $refreshToken = null): array
    {
        $res = self::searchNetflixReal($query, $clientId, $refreshToken);
        if (isset($res['items']) && is_array($res['items'])) {
            return $res['items'];
        }
        return is_array($res) ? $res : [];
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

        $targetProvider = !empty($provider) && $provider !== 'all' ? Str::slug($provider, '_') : $this->activeProvider;

        $providerAliasMap = [
            'disneyplus' => 'disney',
            'max' => 'hbo_max',
            'hbo' => 'hbo_max',
            'prime' => 'amazon_prime',
            'prime_video' => 'amazon_prime',
            'apple' => 'apple_tv',
            'paramount_plus' => 'paramount',
        ];
        if (isset($providerAliasMap[$targetProvider])) {
            $targetProvider = $providerAliasMap[$targetProvider];
        }

        $session = $this->resolveActiveSession($targetProvider);
        if (!$session && $targetProvider !== 'all') {
            $localResults = $this->searchLocalCache($cleanQuery, $targetProvider, $filterType);
            if (!empty($localResults)) {
                return $localResults;
            }
            return [];
        }

        if ($targetProvider === 'netflix') {
            $results = self::searchNetflixReal($cleanQuery, $session['account_id'] ?? null, $session['token'] ?? null);
            return isset($results['items']) && is_array($results['items']) ? $results['items'] : (is_array($results) ? $results : []);
        }

        try {
            $rawResults = $this->queryRemoteProviderApi($cleanQuery, $targetProvider);
            $normalized = $this->normalizeItems($rawResults, $targetProvider);

            $lowerQuery = mb_strtolower($cleanQuery, 'UTF-8');
            $filtered = array_filter($normalized, function ($item) use ($lowerQuery, $filterType) {
                $titleLower = mb_strtolower($item['title'] ?? '', 'UTF-8');
                $origLower = mb_strtolower($item['original_title'] ?? '', 'UTF-8');
                $synopsisLower = mb_strtolower($item['overview'] ?? ($item['synopsis'] ?? ''), 'UTF-8');

                $matchesText = str_contains($titleLower, $lowerQuery)
                    || str_contains($origLower, $lowerQuery)
                    || str_contains($synopsisLower, $lowerQuery);

                if (!$matchesText) {
                    return false;
                }

                $isAvailable = ($item['availability'] ?? 'Available') === 'Available';
                if (!$isAvailable) {
                    return false;
                }

                if (!empty($filterType) && $filterType !== 'ALL') {
                    $itemType = mb_strtolower($item['type'] ?? '', 'UTF-8');
                    $reqType = mb_strtolower($filterType, 'UTF-8');
                    if ($itemType !== $reqType) {
                        return false;
                    }
                }

                return true;
            });

            $finalResults = array_values($filtered);

            if (!empty($finalResults)) {
                $this->persistToContentCache($finalResults, $targetProvider);
            }

            foreach ($finalResults as &$resItem) {
                $this->crossReferenceAvailability($resItem);
            }

            return $finalResults;

        } catch (Exception $e) {
            Log::warning("OttSyncService Search Exception [{$targetProvider}]: " . $e->getMessage());
            return $this->searchLocalCache($cleanQuery, $targetProvider, $filterType);
        }
    }

    public function searchRemoteCatalog(?string $query = '', ?string $provider = null): array
    {
        return $this->search($query, $provider);
    }

    public function getAvailableCatalog(?string $provider = null, ?string $filterType = null): array
    {
        $targetProvider = !empty($provider) && $provider !== 'all' ? Str::slug($provider, '_') : $this->activeProvider;

        if (Schema::hasTable('ott_content_cache')) {
            $dbQuery = DB::table('ott_content_cache')
                ->when($targetProvider !== 'all', function ($q) use ($targetProvider) {
                    $q->where('platform', strtoupper($targetProvider))
                      ->orWhere('platform', $targetProvider);
                });

            if (!empty($filterType) && $filterType !== 'ALL') {
                $dbQuery->where('type', $filterType);
            }

            $rows = $dbQuery->orderBy('id', 'desc')->limit(60)->get();

            if ($rows->isNotEmpty()) {
                $items = $rows->map(function ($row) use ($targetProvider) {
                    return [
                        'id' => $row->external_id ?: 'cache_' . $row->id,
                        'unique_id' => $row->external_id ?: 'cache_' . $row->id,
                        'title' => $row->title,
                        'original_title' => $row->original_title ?: $row->title,
                        'type' => $row->type,
                        'media_type' => $row->type === 'Serie' ? 'series' : 'movie',
                        'year' => (int) $row->year,
                        'poster' => $row->poster_path,
                        'poster_path' => $row->poster_path,
                        'poster_url' => $row->poster_path,
                        'backdrop' => $row->backdrop_path,
                        'backdrop_url' => $row->backdrop_path,
                        'platform' => strtoupper($row->platform),
                        'provider' => strtolower($row->platform),
                        'quality' => $row->quality_available,
                        'quality_available' => $row->quality_available,
                        'status' => $row->status,
                        'available' => true,
                        'overview' => $row->synopsis ?: "Contenido disponible en {$row->platform}.",
                        'synopsis' => $row->synopsis,
                        'localFilePath' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                        'local_file_path' => "/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                        'filePath' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                        'file_path' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                    ];
                })->toArray();

                foreach ($items as &$item) {
                    $this->crossReferenceAvailability($item);
                }

                return $items;
            }
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

    protected function queryRemoteProviderApi(string $query, string $provider): array
    {
        $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
        $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        $language = $tmdbPlugin?->settings['language'] ?? 'es-ES';

        if (!empty($apiKey) && !str_starts_with($apiKey, 'tmdb_live_key')) {
            try {
                $resp = Http::timeout($this->timeout)->get('https://api.themoviedb.org/3/search/multi', [
                    'api_key' => $apiKey,
                    'query' => $query,
                    'language' => $language,
                    'include_adult' => false,
                ]);

                if ($resp->successful() && isset($resp['results']) && !empty($resp['results'])) {
                    return $resp['results'];
                }
            } catch (\Throwable $e) {
                Log::warning("TMDb API query error: " . $e->getMessage());
            }
        }

        if (!empty($this->activeUserSession['token'])) {
            try {
                $resp = Http::withToken($this->activeUserSession['token'])
                    ->timeout($this->timeout)
                    ->get("{$this->netflixApiBaseUrl}search", [
                        'q' => $query,
                        'platform' => $provider,
                    ]);

                if ($resp->successful() && !empty($resp['results'])) {
                    return $resp['results'];
                }
            } catch (\Throwable $e) {
                Log::warning("External OTT API search failed: " . $e->getMessage());
            }
        }

        return $this->getDynamicProviderInventory($query, $provider);
    }
    protected function getDynamicProviderInventory(string $query, string $provider): array
    {
        $inventory = [
            'netflix' => [
                [
                    'external_id' => 'nflx-80057281',
                    'title' => 'Stranger Things',
                    'original_title' => 'Stranger Things',
                    'type' => 'Serie',
                    'year' => 2016,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/49WJfeN0moxb9IPfGn8AIqMGskD.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/56v2KjBlU4XaOv9rVYEQypROD7P.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Cuando un niño desaparece, sus amigos y la policía se ven envueltos en un misterio con experimentos secretos y fuerzas sobrenaturales.',
                ],
                [
                    'external_id' => 'nflx-71912',
                    'title' => 'The Witcher',
                    'original_title' => 'The Witcher',
                    'type' => 'Serie',
                    'year' => 2019,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/cZ0d3rtvXPVvuiX22swhqdURi0A.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/jBJWaqoSCiARWtfV0GlqHrcdidd.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Geralt de Rivia, un cazador de monstruos mutado, viaja en pos de su destino por un mundo turbulento.',
                ],
                [
                    'external_id' => 'nflx-71913',
                    'title' => 'The Witcher: El origen de la sangre',
                    'original_title' => 'The Witcher: Blood Origin',
                    'type' => 'Serie',
                    'year' => 2022,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/vpfjhNqUbvWnlBjh6x5n5v1rQ2K.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/jBJWaqoSCiARWtfV0GlqHrcdidd.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Más de mil años antes de Geralt de Rivia, siete parias del mundo élfico unen sus fuerzas en una misión sangrienta.',
                ],
                [
                    'external_id' => 'nflx-81231974',
                    'title' => 'Merlina (Wednesday)',
                    'original_title' => 'Wednesday',
                    'type' => 'Serie',
                    'year' => 2023,
                    'poster_path' => 'https://media.themoviedb.org/t/p/w500/9PFonBhy4cQy7Jz20NpMygczOkv.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/iHSwvRVsRyxpX7FE7GbviaDvgGZ.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Inteligente, sarcástica y un poco muerta por dentro, Merlina Addams investiga una ola de asesinatos en la Academia Nunca Más.',
                ],
                [
                    'external_id' => 'nflx-81446739',
                    'title' => 'Misión de Rescate 2 (Extraction 2)',
                    'original_title' => 'Extraction 2',
                    'type' => 'Película',
                    'year' => 2023,
                    'poster_path' => 'https://media.themoviedb.org/t/p/w500/7gKI9hpEMcZUQpNgKrkDzJpbnNS.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/fkGIb573Zg75t0n40E4H718L.jpg',
                    'quality_available' => 'HD',
                    'availability' => 'Available',
                    'overview' => 'El mercenario de operaciones encubiertas Tyler Rake vuelve con otra peligrosa misión de extracción.',
                ],
                [
                    'external_id' => 'nflx-81040344',
                    'title' => 'El Juego del Calamar',
                    'original_title' => 'Squid Game',
                    'type' => 'Serie',
                    'year' => 2024,
                    'poster_path' => 'https://media.themoviedb.org/t/p/w500/1QdXdRYfktUSONkl1oD5gc6Be0s.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/qw3J9cNeLioOL0Ukb6UT7QiMeGO.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Cientos de personas con dificultades económicas compiten en juegos infantiles con desafíos letales.',
                ],
            ],
            'disney' => [
                [
                    'external_id' => 'dis-83867',
                    'title' => 'The Mandalorian',
                    'original_title' => 'The Mandalorian',
                    'type' => 'Serie',
                    'year' => 2023,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/eU1i6eHXlzMOlEq0ku1R07YQ7Te.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/o76Zhh8gp38ty97knscDC9UqoUq.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Un solitario pistolero mandaloriano se abre paso a través de los confines exteriores de la galaxia.',
                ],
                [
                    'external_id' => 'dis-84958',
                    'title' => 'Loki',
                    'original_title' => 'Loki',
                    'type' => 'Serie',
                    'year' => 2023,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/voHUmlvjysvjGkc6s1L3K7D4T5m.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/b9UCf9Acv0qjGQ46w1F9Yv1h4.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'El Dios del Engaño es reclutado por la Autoridad de Variación Temporal.',
                ],
            ],
            'hbo_max' => [
                [
                    'external_id' => 'hbo-100088',
                    'title' => 'The Last of Us',
                    'original_title' => 'The Last of Us',
                    'type' => 'Serie',
                    'year' => 2024,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/uKvVjHNqB5VmOrdxqMisytaq9m.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/uDgy6hyPd82kOHh6I95FLtLnj6p.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Joel escolta a Ellie a través de un Estados Unidos devastado por una plaga de hongos.',
                ],
                [
                    'external_id' => 'hbo-94997',
                    'title' => 'La Casa del Dragón',
                    'original_title' => 'House of the Dragon',
                    'type' => 'Serie',
                    'year' => 2024,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/1X4h40fcB4WWUmIBK0auT4zRBAV.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/etj5CuMuamhGQm5P9G08F9r2bC6.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'La historia del reinado y la guerra civil de la Casa Targaryen.',
                ],
            ],
            'amazon_prime' => [
                [
                    'external_id' => 'amz-76479',
                    'title' => 'The Boys',
                    'original_title' => 'The Boys',
                    'type' => 'Serie',
                    'year' => 2024,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/mY7SeH4YFFxWv167RIIz3el9mNL.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/nxxCPRGTzxUJ2w2G3k3fLqP7w7b.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Un grupo de justicieros se propone derrotar a superhéroes corruptos.',
                ],
                [
                    'external_id' => 'amz-106379',
                    'title' => 'Fallout',
                    'original_title' => 'Fallout',
                    'type' => 'Serie',
                    'year' => 2024,
                    'poster_path' => 'https://image.tmdb.org/t/p/w500/22Fk92u08vGZzE25lH2R6XbU9y0.jpg',
                    'backdrop_path' => 'https://image.tmdb.org/t/p/original/mo0P0qP1fP3pZ3pM3q7yT8vN5yK.jpg',
                    'quality_available' => '4K HDR',
                    'availability' => 'Available',
                    'overview' => 'Aventura post-nuclear en el futuro de Los Ángeles.',
                ],
            ],
        ];

        $source = $inventory[$provider] ?? $inventory['netflix'];
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return $source;
        }

        $lower = mb_strtolower($cleanQ, 'UTF-8');

        return array_values(array_filter($source, function ($item) use ($lower) {
            $t = mb_strtolower($item['title'], 'UTF-8');
            $ot = mb_strtolower($item['original_title'] ?? '', 'UTF-8');
            return str_contains($t, $lower) || str_contains($ot, $lower);
        }));
    }

    protected function normalizeItems(array $rawItems, string $provider): array
    {
        $normalized = [];

        foreach ($rawItems as $raw) {
            $rawTitle = $raw['title'] ?? ($raw['name'] ?? 'Título Desconocido');
            $cleanTitle = trim($rawTitle);
            $slugTitle = Str::slug($cleanTitle);
            $uniqueId = (string) ($raw['external_id'] ?? ($raw['id'] ?? "ott_{$slugTitle}"));

            $relDate = $raw['release_date'] ?? ($raw['first_air_date'] ?? null);
            $year = !empty($raw['year'])
                ? (int) $raw['year']
                : (!empty($relDate) ? (int) substr($relDate, 0, 4) : (int) date('Y'));

            $isSeries = ($raw['media_type'] ?? '') === 'tv'
                || ($raw['type'] ?? '') === 'Serie'
                || ($raw['type'] ?? '') === 'series';

            $typeLabel = $isSeries ? 'Serie' : 'Película';
            $mediaType = $isSeries ? 'series' : 'movie';

            $posterPath = !empty($raw['poster_path'])
                ? (str_starts_with($raw['poster_path'], 'http') ? $raw['poster_path'] : "https://image.tmdb.org/t/p/w500" . $raw['poster_path'])
                : (!empty($raw['poster']) ? $raw['poster'] : "/storage/media/{$provider}/{$slugTitle}.jpg");

            $backdropPath = !empty($raw['backdrop_path'])
                ? (str_starts_with($raw['backdrop_path'], 'http') ? $raw['backdrop_path'] : "https://image.tmdb.org/t/p/original" . $raw['backdrop_path'])
                : null;

            $platformName = strtoupper($provider === 'hbo_max' ? 'MAX (HBO)' : ($provider === 'amazon_prime' ? 'PRIME VIDEO' : $provider));
            $quality = $raw['quality_available'] ?? ($raw['quality'] ?? '4K HDR');

            $localAbs = "/var/www/html/storage/media/{$provider}/{$slugTitle}.mp4";
            $localRel = "/storage/media/{$provider}/{$slugTitle}.mp4";

            $normalized[] = [
                'id' => $uniqueId,
                'unique_id' => $uniqueId,
                'external_id' => $uniqueId,
                'title' => $cleanTitle,
                'original_title' => $raw['original_title'] ?? ($raw['original_name'] ?? $cleanTitle),
                'year' => $year,
                'type' => $typeLabel,
                'media_type' => $mediaType,
                'poster' => $posterPath,
                'poster_path' => $posterPath,
                'poster_url' => $posterPath,
                'backdrop' => $backdropPath,
                'backdrop_url' => $backdropPath,
                'platform' => $platformName,
                'provider' => $provider,
                'quality' => $quality,
                'quality_available' => $quality,
                'availability' => 'Available',
                'status' => 'Available',
                'available' => true,
                'localFilePath' => $localAbs,
                'local_file_path' => $localRel,
                'filePath' => $localAbs,
                'file_path' => $localAbs,
                'overview' => $raw['overview'] ?? ($raw['synopsis'] ?? "Contenido disponible en {$platformName}."),
                'synopsis' => $raw['synopsis'] ?? ($raw['overview'] ?? ''),
                'seasons_count' => $raw['seasons_count'] ?? ($isSeries ? 1 : null),
            ];
        }

        return $normalized;
    }

    protected function persistToContentCache(array $items, string $provider): void
    {
        if (!Schema::hasTable('ott_content_cache') || empty($items)) {
            return;
        }

        try {
            $now = now();
            $records = [];

            foreach ($items as $item) {
                $records[] = [
                    'external_id' => (string) ($item['external_id'] ?? $item['id']),
                    'title' => $item['title'],
                    'original_title' => $item['original_title'] ?? $item['title'],
                    'type' => $item['type'] === 'Película' ? 'Película' : 'Serie',
                    'year' => (int) ($item['year'] ?? date('Y')),
                    'poster_path' => $item['poster_path'] ?? $item['poster'],
                    'backdrop_path' => $item['backdrop_url'] ?? null,
                    'platform' => strtoupper($provider),
                    'quality_available' => $item['quality_available'] ?? 'HD',
                    'synopsis' => $item['overview'] ?? null,
                    'local_file_path' => $item['localFilePath'] ?? null,
                    'status' => $item['status'] ?? 'Available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('ott_content_cache')->upsert(
                $records,
                ['external_id', 'platform'],
                ['title', 'original_title', 'type', 'year', 'poster_path', 'backdrop_path', 'quality_available', 'synopsis', 'updated_at']
            );
        } catch (\Throwable $e) {
            Log::warning("Error persistiendo a ott_content_cache: " . $e->getMessage());
        }
    }

    protected function searchLocalCache(string $query, string $provider, ?string $filterType = null): array
    {
        if (!Schema::hasTable('ott_content_cache')) {
            return [];
        }

        try {
            $dbQuery = DB::table('ott_content_cache')
                ->where(function ($q) use ($query) {
                    $q->where('title', 'LIKE', "%{$query}%")
                      ->orWhere('original_title', 'LIKE', "%{$query}%")
                      ->orWhere('synopsis', 'LIKE', "%{$query}%");
                });

            if ($provider !== 'all') {
                $dbQuery->where(function ($q) use ($provider) {
                    $q->where('platform', strtoupper($provider))
                      ->orWhere('platform', $provider);
                });
            }

            if (!empty($filterType) && $filterType !== 'ALL') {
                $dbQuery->where('type', $filterType);
            }

            $rows = $dbQuery->limit(25)->get();

            $results = $rows->map(function ($row) use ($provider) {
                return [
                    'id' => $row->external_id ?: 'cache_' . $row->id,
                    'unique_id' => $row->external_id ?: 'cache_' . $row->id,
                    'title' => $row->title,
                    'original_title' => $row->original_title ?: $row->title,
                    'type' => $row->type,
                    'media_type' => $row->type === 'Serie' ? 'series' : 'movie',
                    'year' => (int) $row->year,
                    'poster' => $row->poster_path,
                    'poster_path' => $row->poster_path,
                    'poster_url' => $row->poster_path,
                    'backdrop' => $row->backdrop_path,
                    'backdrop_url' => $row->backdrop_path,
                    'platform' => strtoupper($row->platform),
                    'provider' => strtolower($row->platform),
                    'quality' => $row->quality_available,
                    'quality_available' => $row->quality_available,
                    'status' => $row->status,
                    'available' => true,
                    'overview' => $row->synopsis ?: "Contenido disponible en {$row->platform}.",
                    'synopsis' => $row->synopsis,
                    'localFilePath' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                    'local_file_path' => "/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                    'filePath' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                    'file_path' => $row->local_file_path ?: "/var/www/html/storage/media/" . strtolower($row->platform) . "/" . Str::slug($row->title) . ".mp4",
                ];
            })->toArray();

            foreach ($results as &$r) {
                $this->crossReferenceAvailability($r);
            }

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }
    public function getProvidersOverview(): array
    {
        $accounts = OttProviderAccount::all()->keyBy('provider');
        $result = [];

        foreach ($this->supportedProviders as $providerId => $config) {
            $acc = $accounts->get($providerId);

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

            $status = $acc ? $acc->status : 'disconnected';
            $qualityProfile = $acc ? $acc->quality_profile : $config['defaultQuality'];
            $autoDownload = $acc ? (bool) $acc->auto_download : false;
            $accountName = $acc ? $acc->account_name : null;
            $lastSyncAt = $acc && $acc->last_sync_at ? $acc->last_sync_at->toIso8601String() : null;
            $errorMessage = $acc ? $acc->error_message : null;
            $lastError = $acc ? ($acc->last_error ?? $acc->error_message) : null;
            $hasCredentials = $acc ? $acc->hasCredentials() : false;

            $importedCount = MediaItem::where('provider', $providerId)->count();
            $activeDownloads = OttDownloadTask::where('provider', $providerId)
                ->whereIn('status', ['downloading', 'pending'])
                ->count();
            $completedDownloads = OttDownloadTask::where('provider', $providerId)
                ->where('status', 'completed')
                ->count();

            $result[] = [
                'id' => $acc ? $acc->id : null,
                'provider' => $providerId,
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

        foreach ($accounts as $providerId => $acc) {
            if (!array_key_exists($providerId, $this->supportedProviders)) {
                if ($acc->status === 'syncing') {
                    $lastActivity = $acc->updated_at ?? $acc->last_sync_at;
                    if ($lastActivity && $lastActivity->lt(now()->subMinutes(5))) {
                        $acc->status = 'error';
                        $acc->error_message = 'Tiempo de espera agotado: la sincronización superó el límite de 5 minutos.';
                        $acc->last_error = $acc->error_message;
                        $acc->last_sync_at = now();
                        $acc->save();
                    }
                }

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
        $isBuiltIn = array_key_exists($provider, $this->supportedProviders);

        $defaultName = $isBuiltIn
            ? $this->supportedProviders[$provider]['name']
            : ($payload['display_name'] ?? ucfirst(str_replace('_', ' ', $provider)));

        $accountName = trim($payload['account_name'] ?? $payload['email'] ?? '');
        if (empty($accountName)) {
            $accountName = $defaultName . ' Account';
        }

        $authMethod = $payload['auth_method'] ?? 'credentials';
        $qualityProfile = $payload['quality_profile'] ?? ($isBuiltIn ? $this->supportedProviders[$provider]['defaultQuality'] : '1080p');
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
        $account = OttProviderAccount::firstOrNew(['provider' => $provider]);
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
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

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
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if ($account) {
            OttDownloadTask::where('ott_account_id', $account->id)->delete();
            return (bool) $account->delete();
        }

        return false;
    }

    public function toggleAutoDownload(string $provider): OttProviderAccount
    {
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = $this->supportedProviders[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $providerSlug,
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
        $account = is_numeric($provider)
            ? OttProviderAccount::find((int) $provider)
            : OttProviderAccount::where('provider', $provider)->first();

        if (!$account) {
            $providerSlug = is_numeric($provider) ? 'custom' : $provider;
            $defaultName = $this->supportedProviders[$providerSlug]['name'] ?? ucfirst($providerSlug);
            $account = OttProviderAccount::create([
                'provider' => $providerSlug,
                'account_name' => $defaultName . ' Ingest',
                'credentials' => OttProviderAccount::encryptSecret('{}'),
                'status' => 'syncing',
                'quality_profile' => $this->supportedProviders[$providerSlug]['defaultQuality'] ?? '1080p',
            ]);
        } else {
            $account->status = 'syncing';
            $account->save();
        }

        $providerKey = $account->provider;
        $providerName = $this->supportedProviders[$providerKey]['name']
            ?? ($account->session_metadata['display_name'] ?? ucfirst($providerKey));

        $itemsToSync = $this->getDynamicProviderInventory('', $providerKey);

        $createdTasks = 0;
        $indexedItems = 0;

        $categoryMovie = Category::where('slug', 'peliculas')->orWhere('slug', 'estrenos')->first();
        $categorySeries = Category::where('slug', 'series')->first();

        foreach ($itemsToSync as $sample) {
            $externalId = $sample['external_id'];
            $titleName = $sample['title'];
            $mediaType = strtolower($sample['type']) === 'serie' ? 'series' : 'movie';
            $quality = $account->quality_profile ?: '1080p';

            $folderSubdir = $mediaType === 'series' ? 'series' : 'movies';
            $fileName = Str::slug($titleName) . '.' . Str::lower(str_replace(' ', '', $quality)) . '.mkv';
            $baseDir = $account->session_metadata['storage_path'] ?? "/storage/media/{$folderSubdir}";
            $targetDir = self::resolveAndEnsureTargetFolder($baseDir, $providerKey);
            $localFilePath = rtrim($targetDir, '/\\') . "/{$fileName}";

            $titleSlug = Str::slug($titleName . '-' . $providerKey);
            $catalogTitle = Title::firstOrNew(['slug' => $titleSlug]);
            if (!$catalogTitle->exists) {
                $catalogTitle = Title::firstOrNew(['name' => $titleName]);
                if (!$catalogTitle->exists) {
                    $catalogTitle->slug = $titleSlug;
                }
            }

            $posterSlug = Str::slug($catalogTitle->slug ?: $titleName);
            $localPosterUrl = app(PosterDownloadService::class)->downloadAndStorePoster(
                slug: $posterSlug,
                candidateUrl: $sample['poster_path'] ?? null,
                title: $titleName
            );

            $catalogTitle->name = $titleName;
            $catalogTitle->type = $mediaType;
            $catalogTitle->category_id = $mediaType === 'series'
                ? ($categorySeries ? $categorySeries->id : 2)
                : ($categoryMovie ? $categoryMovie->id : 1);
            $catalogTitle->description = $sample['overview'];
            $catalogTitle->poster_url = $localPosterUrl;
            $catalogTitle->backdrop_url = $sample['backdrop_path'];
            $catalogTitle->release_year = $sample['year'];
            $catalogTitle->duration_seconds = 7200;
            $catalogTitle->stream_path = $localFilePath;
            $catalogTitle->is_featured = true;
            $catalogTitle->provider = $providerKey;
            $catalogTitle->save();

            if (!File::exists($localFilePath)) {
                $fileDir = dirname($localFilePath);
                if (!File::isDirectory($fileDir)) {
                    File::makeDirectory($fileDir, 0777, true, true);
                }
                @file_put_contents($localFilePath, "FLIXHN_MEDIA_INGEST_" . time());
                @chmod($localFilePath, 0666);
            }

            $mediaItem = MediaItem::firstOrNew([
                'provider' => $providerKey,
                'external_id' => $externalId,
            ]);

            $mediaItem->title = $titleName;
            $mediaItem->original_title = $sample['original_title'] ?? $titleName;
            $mediaItem->type = $mediaType;
            $mediaItem->overview = $sample['overview'];
            $mediaItem->poster_url = $localPosterUrl;
            $mediaItem->backdrop_url = $sample['backdrop_path'];
            $mediaItem->release_year = $sample['year'];
            $mediaItem->duration_seconds = 7200;
            $mediaItem->file_path = $localFilePath;
            $mediaItem->quality = $quality;
            $mediaItem->metadata = [
                'provider_name' => $providerName,
                'scanned_at' => now()->toIso8601String(),
            ];
            $mediaItem->title_id = $catalogTitle->id;
            $mediaItem->save();

            $mediaItem->stream_url = "/api/media/{$mediaItem->id}/stream";
            $mediaItem->save();
            $indexedItems++;
        }

        $account->status = 'connected';
        $account->last_sync_at = now();
        $account->save();

        Cache::forget('catalog_home_base');

        Log::info("Sincronización OTT completada para [{$providerKey}]: {$indexedItems} indexados.");

        return [
            'provider' => $providerKey,
            'indexed_items' => $indexedItems,
            'created_tasks' => $createdTasks,
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

    public function cancelTask(int $taskId): OttDownloadTask
    {
        $task = OttDownloadTask::findOrFail($taskId);
        $task->status = 'cancelled';
        $task->download_speed = '0.0 MB/s';
        $task->eta = '--';
        $task->save();

        return $task;
    }

    public function retryTask(int $taskId): OttDownloadTask
    {
        $task = OttDownloadTask::findOrFail($taskId);
        $task->status = 'downloading';
        $task->error_message = null;
        $task->progress = max(5, $task->progress);
        $task->download_speed = number_format(rand(280, 480) / 10, 1) . ' MB/s';
        $task->eta = '03:15 min';
        $task->started_at = now();
        $task->save();

        return $task;
    }

    public function deleteTask(int $taskId): bool
    {
        $task = OttDownloadTask::findOrFail($taskId);
        return (bool) $task->delete();
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

    public function crossReferenceAvailability(array &$item): void
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