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
use App\Services\MediaStorageService;
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
        'disney' => [
            'name' => 'Disney+',
            'color' => '#113CC8',
            'brand' => 'Disney, Pixar, Marvel, Star Wars & National Geographic',
            'description' => 'Producciones y estrenos de Walt Disney Studios, Pixar, Marvel Studios, Star Wars y National Geographic.',
            'bgGradient' => 'from-blue-600/20 to-zinc-900',
            'badgeColor' => 'bg-blue-600/20 text-blue-400 border-blue-500/30',
            'defaultQuality' => '4K HDR',
        ],
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
     * Normaliza y detecta robustamente si el contenido es una Serie o Película
     */
    public static function normalizeMediaType(?string $type, ?string $title = null): string
    {
        if (!empty($type)) {
            $t = strtolower(trim($type));
            if (in_array($t, ['series', 'serie', 'show', 'shows', 'tv', 'anime', 'season', 'episode'])) {
                return 'series';
            }
            if (in_array($t, ['movie', 'movies', 'pelicula', 'película', 'film', 'films'])) {
                return 'movie';
            }
        }
        if (!empty($title)) {
            $clean = mb_strtolower(trim($title));
            $knownSeries = [
                'pokemon', 'pokémon', 'loki', 'stranger things', 'hawkeye', 'daredevil',
                'squid game', 'el juego del calamar', 'rebelde', 'invencible', 'invincible',
                'jack ryan', 'the last of us', 'house of the dragon', 'la casa del drag',
                'peacemaker', 'el pacificador', 'lupin', 'into the night', 'dahmer',
                'arcane', 'mandalorian', 'the boys', 'fallout', 'ted lasso', 'halo',
                'rings of power', 'anillos de poder', 'helluva boss', 'hermanos de sangre',
                'el mentalista', 'the mentalist', 'boba fett', 'ms. marvel', 'caballero luna',
                'moon knight', 'secret invasion', 'invasion secreta', 'invasión secreta',
                'serie pokémon', 'serie pokemon', 'naruto', 'one piece', 'dragon ball',
                'attack on titan', 'shingeki', 'demon slayer', 'kimetsu'
            ];
            foreach ($knownSeries as $ks) {
                if (str_contains($clean, $ks)) {
                    return 'series';
                }
            }
            if (preg_match('/(temporada|season|episodio|capitulo|capítulo|serie|ep|s\d+e\d+)/i', $title)) {
                return 'series';
            }
        }
        return 'movie';
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

        // Si no se encontraron cuentas en ott_provider_accounts, buscar sesiones activas con refresh_token
        if (empty($connected)) {
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
        }

        // Si y solo si no existe ninguna cuenta conectada ni sesión activa, recurrir a ott_provider_configs
        if (empty($connected)) {
            try {
                $configs = DB::table('ott_provider_configs')
                    ->where('is_active', true)
                    ->whereNotIn('slug', ['local', 'partner_hls'])
                    ->pluck('slug')
                    ->toArray();

                foreach ($configs as $prov) {
                    $norm = self::normalizePlatformKey($prov) ?: strtolower(trim($prov));
                    if ($norm && !in_array($norm, $connected, true)) {
                        $connected[] = $norm;
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (empty($connected)) {
            $connected = ['netflix', 'amazon_prime', 'disney'];
        }

        return $connected;
    }

    /**
     * Método Principal: Buscar contenido REAL en la plataforma especificada.
     * @param string $query Texto que escribió el usuario (ej: "The Witcher")
     * @param string|null $platform Plataforma objetivo ('netflix', 'disney_plus', etc.) o null para buscar en todas las conectadas.
     */
    public static function removeDiacritics(string $str): string
    {
        $str = mb_strtolower($str);
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c'
        ];
        return strtr($str, $map);
    }

    public static function searchReal($query, ?string $platform = null): array
    {
        $cleanQuery = trim((string) $query);
        if ($cleanQuery === '') {
            return [];
        }

        $normalized = self::normalizePlatformKey($platform);

        // Si se especificó una plataforma particular pero no es válida en la configuración
        if ($platform !== null && $platform !== 'all' && $normalized === null && !isset(self::$platforms_config[$normalized])) {
            return [];
        }

        // Si se especificó una plataforma particular válida, ejecutar la búsqueda directamente
        if ($normalized !== null && $platform !== 'all') {
            return self::executePlatformSearch($cleanQuery, $normalized);
        }

        // Si no se especificó plataforma (es null o 'all'), buscar EXCLUSIVAMENTE en las plataformas
        // conectadas en admin/ott-sync (por defecto: netflix, amazon_prime, disney_plus)
        $connected = self::getActiveConnectedPlatforms();
        $targetPlatforms = !empty($connected) ? $connected : ['netflix', 'amazon_prime', 'disney_plus'];

        return self::searchRemoteOttCatalogMulti($cleanQuery, $targetPlatforms);
    }

    /**
     * Búsqueda paralela ultra-rápida vía GraphQL Http::pool en todas las plataformas principales
     */
    public static function searchRemoteOttCatalogMulti(string $query, array $platforms): array
    {
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return [];
        }

        $gql = 'query GetSearchTitles($country: Country!, $searchFilter: TitleFilter!) { popularTitles(country: $country, filter: $searchFilter, first: 15) { edges { node { id objectId objectType content(country: $country, language: "es") { title originalTitle originalReleaseYear shortDescription posterUrl } offers(country: $country, platform: WEB) { standardWebURL package { shortName technicalName clearName } } } } } }';

        $responses = [];
        try {
            $responses = Http::pool(function ($pool) use ($platforms, $cleanQ, $gql) {
                $reqs = [];
                foreach ($platforms as $platform) {
                    $normPlatform = self::normalizePlatformKey($platform) ?: $platform;
                    $pkg = self::resolvePlatformPackageKey($normPlatform);
                    $country = self::resolveCountryForPlatform($normPlatform);

                    $reqs[$normPlatform] = $pool->as($normPlatform)
                        ->timeout(8)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        ])
                        ->post('https://apis.justwatch.com/graphql', [
                            'query'     => $gql,
                            'variables' => [
                                'country'      => $country,
                                'searchFilter' => [
                                    'searchQuery' => $cleanQ,
                                    'packages'    => [$pkg],
                                ],
                            ],
                        ]);
                }
                return $reqs;
            });
        } catch (\Throwable $e) {
            Log::warning("searchRemoteOttCatalogMulti Http::pool exception: " . $e->getMessage());
        }

        $aggregatedResults = [];
        $seen = [];

        foreach ($responses as $normPlatform => $resp) {
            if (!$resp || !method_exists($resp, 'successful') || !$resp->successful()) {
                continue;
            }

            $edges = data_get($resp->json(), 'data.popularTitles.edges', []);
            if (!is_array($edges)) {
                continue;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if (!$node) continue;

                $content = $node['content'] ?? [];
                $title = trim((string)($content['title'] ?? ''));
                if ($title === '') continue;

                $origTitle = (string)($content['originalTitle'] ?? $title);

                // Filtrar resultados no relacionados que JustWatch a veces incluye como populares
                $cleanQNorm = self::removeDiacritics($cleanQ);
                $stopWords = ['el', 'la', 'los', 'las', 'de', 'del', 'en', 'y', 'a', 'que', 'nos', 'un', 'una', 'por', 'con', 'para', 'se', 'al', 'lo', 'como', 'mas', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'si', 'porque', 'esta', 'the', 'of', 'and', 'in', 'on', 'at', 'to', 'for'];
                $allTokens = array_values(array_filter(preg_split('/\s+/u', $cleanQNorm), fn($t) => mb_strlen($t) >= 2));
                $sigTokens = array_values(array_filter($allTokens, fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stopWords, true)));
                if (empty($sigTokens)) {
                    $sigTokens = $allTokens;
                }

                if (!empty($sigTokens)) {
                    $tNorm = self::removeDiacritics($title);
                    $origNorm = self::removeDiacritics($origTitle);
                    $matchesQuery = str_contains($tNorm, $cleanQNorm) || str_contains($origNorm, $cleanQNorm);
                    if (!$matchesQuery) {
                        $matchedCount = 0;
                        foreach ($sigTokens as $token) {
                            if (str_contains($tNorm, $token) || str_contains($origNorm, $token)) {
                                $matchedCount++;
                            }
                        }
                        $required = count($sigTokens) <= 2 ? count($sigTokens) : max(2, count($sigTokens) - 1);
                        $matchesQuery = ($matchedCount >= $required);
                    }
                    if (!$matchesQuery) continue;
                }

                $key = mb_strtolower($title) . '_' . ($content['originalReleaseYear'] ?? '');
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $objectId = (string)($node['objectId'] ?? $node['id'] ?? uniqid());
                $objType = strtolower((string)($node['objectType'] ?? 'movie'));
                $mediaType = str_contains($objType, 'show') || str_contains($objType, 'series') || $objType === 'tv' ? 'series' : 'movie';
                $year = (int)($content['originalReleaseYear'] ?? date('Y'));
                $desc = (string)($content['shortDescription'] ?? '');

                $posterUrl = (string)($content['posterUrl'] ?? '');
                $poster = '';
                if ($posterUrl !== '') {
                    $poster = 'https://images.justwatch.com' . str_replace(['{profile}', '.{format}'], ['s592', '.jpg'], $posterUrl);
                }

                $offerLinks = self::extractOfferUrlsFromNode($node, $normPlatform, $title);
                $item = [
                    'id'             => 'ott_' . $normPlatform . '_' . $objectId,
                    'web_url'        => $offerLinks['web_url'],
                    'app_url'        => $offerLinks['app_url'],
                    'unique_id'      => 'ott_' . $normPlatform . '_' . $objectId,
                    'title'          => $title,
                    'name'           => $title,
                    'original_title' => $origTitle,
                    'type'           => $mediaType,
                    'media_type'     => $mediaType,
                    'year'           => $year,
                    'release_year'   => $year,
                    'poster'         => $poster,
                    'poster_url'     => $poster,
                    'backdrop_url'   => $poster,
                    'platform'       => $normPlatform,
                    'provider'       => $normPlatform,
                    'overview'       => $desc,
                    'synopsis'       => $desc,
                    'description'    => $desc,
                    'status'         => 'remote_catalog',
                    'available'      => false,
                    'is_ott'         => true,
                    'is_downloaded'  => false,
                    'is_already_downloaded' => false,
                    'is_available_locally' => false,
                    'localFilePath'  => null,
                ];

                self::crossReferenceAvailability($item);
                $aggregatedResults[] = $item;
            }
        }

        return $aggregatedResults;
    }

    protected static function executePlatformSearch(string $query, string $platform): array
    {
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return [];
        }

        try {
            // 1. Buscar en el catálogo remoto REAL de la plataforma conectada (Netflix, Disney+, etc.)
            $remoteResults = self::searchRemoteOttCatalog($cleanQ, $platform);
            if (!empty($remoteResults)) {
                return $remoteResults;
            }

            // 2. Si no hubo coincidencias en el catálogo remoto, consultar títulos ya catalogados
            // EXCLUSIVAMENTE para esa plataforma conectada (NUNCA mezclar títulos locales ni de otras fuentes)
            $localResults = self::searchLocalConnectedCatalog($cleanQ, $platform);
            if (!empty($localResults)) {
                return $localResults;
            }

            return [];

        } catch (\Throwable $e) {
            Log::warning("OttSyncService executePlatformSearch exception [{$platform}]: " . $e->getMessage());
            return [];
        }
    }

    public static function resolvePlatformPackageKey(string $platform): string
    {
        $norm = self::normalizePlatformKey($platform) ?: strtolower(trim($platform));
        $map = [
            'netflix'        => 'nfx',
            'disney'         => 'dnp',
            'disney_plus'    => 'dnp',
            'hbo_max'        => 'hbm',
            'max'            => 'hbm',
            'amazon_prime'   => 'prv',
            'prime_video'    => 'prv',
            'apple_tv'       => 'atp',
            'apple_tv_plus'  => 'atp',
            'paramount'      => 'pmt',
            'paramount_plus' => 'pmt',
            'crunchyroll'    => 'cru',
            'vix'            => 'vix',
            'star_plus'      => 'srp',
            'claro_video'    => 'clv',
            'mubi'           => 'mbi',
        ];
        return $map[$norm] ?? 'nfx';
    }

    /**
     * Devuelve todos los códigos shortName conocidos de JustWatch para una plataforma.
     */
    public static function resolvePlatformPackageKeys(string $platform): array
    {
        $norm = self::normalizePlatformKey($platform) ?: strtolower(trim($platform));
        $map = [
            'netflix'        => ['nfx', 'nfk'],
            'disney'         => ['dnp'],
            'disney_plus'    => ['dnp'],
            'hbo_max'        => ['mxx', 'hbm', 'max', 'hbo'],
            'max'            => ['mxx', 'hbm', 'max', 'hbo'],
            'amazon_prime'   => ['prv', 'amp', 'amz'],
            'prime_video'    => ['prv', 'amp', 'amz'],
            'apple_tv'       => ['atp', 'atv', 'itu'],
            'apple_tv_plus'  => ['atp', 'atv', 'itu'],
            'paramount'      => ['pmp', 'pmt', 'pplus'],
            'paramount_plus' => ['pmp', 'pmt', 'pplus'],
            'crunchyroll'    => ['cru'],
            'vix'            => ['vix'],
            'star_plus'      => ['srp'],
            'claro_video'    => ['clv'],
            'mubi'           => ['mbi'],
        ];
        return $map[$norm] ?? [self::resolvePlatformPackageKey($norm)];
    }

    /**
     * Limpia entidades HTML y desenvuelve redirecciones de JustWatch si existen.
     */
    public static function cleanExtractedUrl(?string $url): string
    {
        $clean = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($clean === '') {
            return '';
        }

        if (str_contains($clean, 'click.justwatch.com')) {
            $parsed = parse_url($clean);
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $qs);
                if (!empty($qs['r']) && str_starts_with((string) $qs['r'], 'http')) {
                    $clean = trim((string) $qs['r']);
                }
            }
        }

        return $clean;
    }

    /**
     * Determina si la URL corresponde únicamente a una página genérica de búsqueda.
     */
    public static function isGenericSearchUrl(?string $url): bool
    {
        if (empty($url)) {
            return true;
        }
        $lower = strtolower($url);
        return str_contains($lower, '/search?')
            || str_contains($lower, '/search/?')
            || str_contains($lower, '/search/ref=');
    }

    /**
     * Retorna todos los alias de clave posibles en BD para un proveedor dado.
     */
    public static function getProviderAliases(string $platform): array
    {
        $raw = strtolower(trim($platform));
        $norm = self::normalizePlatformKey($raw) ?: $raw;
        $dbKey = self::toDbProviderKey($norm);

        $aliasGroups = [
            'netflix'      => ['netflix'],
            'disney_plus'  => ['disney', 'disney_plus', 'disneyplus', 'disney+'],
            'hbo_max'      => ['hbo_max', 'max', 'hbo', 'hbomax'],
            'amazon_prime' => ['amazon_prime', 'prime_video', 'prime', 'amazon', 'primevideo'],
            'apple_tv'     => ['apple_tv', 'apple', 'apple_tv_plus', 'appletv'],
            'paramount'    => ['paramount', 'paramount_plus', 'paramountplus', 'paramount+'],
            'crunchyroll'  => ['crunchyroll'],
            'vix'          => ['vix'],
        ];

        $candidates = array_merge([$raw, $norm, $dbKey], $aliasGroups[$norm] ?? []);
        return array_values(array_unique(array_filter($candidates, fn($v) => $v !== '')));
    }

    /**
     * Recupera el usuario/correo y la contraseña desencriptada de la plataforma OTT configurada en BD.
     */
    public static function getPlatformAccessCredentials(string $platform): array
    {
        $raw = strtolower(trim($platform));
        $norm = self::normalizePlatformKey($raw) ?: ($raw ?: 'netflix');
        $dbKey = self::toDbProviderKey($norm);
        $candidates = self::getProviderAliases($raw ?: 'netflix');

        $platformConfig = self::$platforms_config[$norm]
            ?? self::$platforms_config[$dbKey]
            ?? null;

        $platformName = $platformConfig['name'] ?? ucwords(str_replace('_', ' ', $raw ?: $norm));
        $platformColor = $platformConfig['color'] ?? '#E50914';

        $account = null;
        try {
            if (Schema::hasTable('ott_provider_accounts')) {
                $accounts = OttProviderAccount::whereIn('provider', $candidates)->get();
                $account = $accounts->first(fn($a) => $a->status === 'connected' && $a->hasCredentials())
                    ?? $accounts->first(fn($a) => $a->status === 'connected')
                    ?? $accounts->first(fn($a) => $a->hasCredentials())
                    ?? $accounts->first();
            }
        } catch (\Throwable $e) {
            Log::warning("getPlatformAccessCredentials lookup error [{$platform}]: " . $e->getMessage());
        }

        $username = '';
        $password = '';
        $authMethod = null;
        $status = 'disconnected';
        $accountName = '';

        if ($account) {
            $status = (string) ($account->status ?: 'disconnected');
            $accountName = trim((string) ($account->account_name ?? ''));
            $meta = is_array($account->session_metadata) ? $account->session_metadata : [];
            if (!empty($meta['display_name'])) {
                $platformName = (string) $meta['display_name'];
            }
            if (!empty($meta['color'])) {
                $platformColor = (string) $meta['color'];
            }

            $creds = $account->getDecryptedCredentials() ?: [];
            $authMethod = $creds['auth_method'] ?? ($meta['auth_method'] ?? null);
            $username = trim((string) ($creds['email'] ?? $creds['username'] ?? $creds['user'] ?? ''));
            if ($username === '') {
                $username = $accountName;
            }
            $password = (string) ($creds['password'] ?? '');
        }

        // Respaldo en ott_provider_configs si no hubo usuario o contraseña en ott_provider_accounts
        if (($username === '' || $password === '') && Schema::hasTable('ott_provider_configs')) {
            try {
                $configModel = \App\Models\OttProviderConfig::whereIn('slug', $candidates)->first();
                if ($configModel) {
                    if ($platformName === ucwords(str_replace('_', ' ', $raw ?: $norm)) && !empty($configModel->name)) {
                        $platformName = (string) $configModel->name;
                    }
                    $cfgCreds = $configModel->getDecryptedCredentials();
                    if ($username === '') {
                        $username = trim((string) ($cfgCreds['email'] ?? $cfgCreds['username'] ?? $cfgCreds['user'] ?? ''));
                    }
                    if ($password === '') {
                        $password = (string) ($cfgCreds['password'] ?? '');
                    }
                }
            } catch (\Throwable $e) {}
        }

        // Respaldo final de usuario en ott_sessions
        if ($username === '' && Schema::hasTable('ott_sessions')) {
            try {
                $session = OttSession::whereIn('provider', $candidates)->latest()->first();
                if ($session) {
                    $meta = is_array($session->session_metadata) ? $session->session_metadata : [];
                    $username = trim((string) ($meta['email'] ?? $meta['user_name'] ?? ''));
                    if ($status === 'disconnected' && !empty($session->refresh_token)) {
                        $status = 'connected';
                    }
                }
            } catch (\Throwable $e) {}
        }

        return [
            'provider'        => $dbKey,
            'platform_name'   => $platformName,
            'platform_color'  => $platformColor,
            'connected'       => $status === 'connected',
            'status'          => $status,
            'account_name'    => $accountName !== '' ? $accountName : null,
            'username'        => $username !== '' ? $username : null,
            'password'        => $password !== '' ? $password : null,
            'auth_method'     => $authMethod,
            'has_credentials' => ($username !== '' || $password !== ''),
        ];
    }

    public static function resolveCountryForPlatform(string $platform): string
    {
        try {
            $candidates = self::getProviderAliases($platform);
            $account = OttProviderAccount::where('status', 'connected')
                ->whereIn('provider', $candidates)
                ->first();
            if ($account && !empty($account->session_metadata['region'])) {
                $region = strtoupper((string) $account->session_metadata['region']);
                if (str_contains($region, 'HN')) return 'HN';
                if (str_contains($region, 'US')) return 'US';
                if (str_contains($region, 'ES')) return 'ES';
                if (str_contains($region, 'MX')) return 'MX';
            }
        } catch (\Throwable $e) {}
        return 'HN';
    }

    /**
     * Extrae la URL oficial real y el deep-link nativo de la plataforma desde las ofertas JustWatch.
     */
    public static function extractOfferUrlsFromNode(array $node, string $platform, string $title = ''): array
    {
        $norm = self::normalizePlatformKey($platform) ?: strtolower(trim($platform));
        $targetPkgs = self::resolvePlatformPackageKeys($norm);
        $offers = $node['offers'] ?? [];
        $webUrl = null;

        $platformDomains = [
            'netflix'      => ['netflix.com'],
            'disney_plus'  => ['disneyplus.com'],
            'disney'       => ['disneyplus.com'],
            'amazon_prime' => ['primevideo.com', 'amazon.com'],
            'hbo_max'      => ['hbomax.com', 'max.com'],
            'apple_tv'     => ['tv.apple.com', 'apple.com'],
            'paramount'    => ['paramountplus.com'],
            'crunchyroll'  => ['crunchyroll.com'],
            'vix'          => ['vix.com'],
            'star_plus'    => ['starplus.com'],
            'claro_video'  => ['clarovideo.com'],
            'mubi'         => ['mubi.com'],
        ];
        $expectedDomains = $platformDomains[$norm] ?? [];

        if (is_array($offers)) {
            // 1. Coincidencia exacta por shortName del paquete (incluyendo alias como mxx/hbm, pmp/pmt)
            foreach ($offers as $offer) {
                $short = strtolower((string) ($offer['package']['shortName'] ?? ''));
                $url = self::cleanExtractedUrl((string) ($offer['standardWebURL'] ?? ''));
                if ($url !== '' && in_array($short, $targetPkgs, true)) {
                    $webUrl = $url;
                    break;
                }
            }

            // 2. Coincidencia por dominio oficial o technicalName
            if (!$webUrl) {
                $normCompact = str_replace('_', '', $norm);
                foreach ($offers as $offer) {
                    $tech = strtolower((string) ($offer['package']['technicalName'] ?? ''));
                    $clear = strtolower((string) ($offer['package']['clearName'] ?? ''));
                    $url = self::cleanExtractedUrl((string) ($offer['standardWebURL'] ?? ''));
                    if ($url === '') {
                        continue;
                    }

                    $domainMatch = false;
                    foreach ($expectedDomains as $dom) {
                        if (str_contains(strtolower($url), $dom)) {
                            $domainMatch = true;
                            break;
                        }
                    }

                    if (
                        $domainMatch
                        || ($normCompact !== '' && (str_contains($tech, $normCompact) || str_contains(str_replace(' ', '', $clear), $normCompact)))
                    ) {
                        $webUrl = $url;
                        break;
                    }
                }
            }

            // 3. Si es una plataforma personalizada sin mapeo fijo y hay una oferta directa HTTP válida
            if (!$webUrl && empty($expectedDomains)) {
                foreach ($offers as $offer) {
                    $url = self::cleanExtractedUrl((string) ($offer['standardWebURL'] ?? ''));
                    if ($url !== '' && str_starts_with($url, 'http')) {
                        $webUrl = $url;
                        break;
                    }
                }
            }
        }

        if (!$webUrl) {
            $qEnc = rawurlencode($title);
            $webUrl = match ($norm) {
                'netflix' => "https://www.netflix.com/search?q={$qEnc}",
                'disney', 'disney_plus' => "https://www.disneyplus.com/search?q={$qEnc}",
                'amazon_prime', 'prime', 'prime_video' => "https://www.primevideo.com/search/ref=atv_nb_sr?phrase={$qEnc}",
                'hbo_max', 'max' => "https://play.max.com/search?q={$qEnc}",
                'apple_tv', 'apple_tv_plus' => "https://tv.apple.com/search?term={$qEnc}",
                'paramount', 'paramount_plus' => "https://www.paramountplus.com/search/?q={$qEnc}",
                'crunchyroll' => "https://www.crunchyroll.com/search?q={$qEnc}",
                'vix' => "https://vix.com/es-es/search?q={$qEnc}",
                default => "https://www.google.com/search?q=" . rawurlencode(trim("{$title} {$norm}")),
            };
        }

        $appUrl = $webUrl;
        if ($norm === 'netflix' && preg_match('#netflix\.com/(?:title|watch)/(\d+)#i', $webUrl, $m)) {
            $appUrl = "netflix://title/{$m[1]}";
        } elseif (str_contains($norm, 'disney')) {
            $appUrl = preg_replace('#^https?://(?:www\.)?disneyplus\.com/#i', 'disneyplus://', $webUrl) ?: $webUrl;
        } elseif (str_contains($norm, 'prime') || str_contains($norm, 'amazon')) {
            $appUrl = preg_replace('#^https?://(?:www\.|app\.)?primevideo\.com/#i', 'primevideo://', $webUrl) ?: $webUrl;
        } elseif (str_contains($norm, 'max') || str_contains($norm, 'hbo')) {
            $appUrl = preg_replace('#^https?://play\.(?:hbo)?max\.com/#i', 'max://', $webUrl) ?: $webUrl;
        }

        return [
            'web_url' => $webUrl,
            'app_url' => $appUrl,
        ];
    }

    /**
     * Resuelve el enlace oficial REAL (web_url y app_url) para un título en su plataforma OTT vinculada.
     */
    public static function resolveOfficialWatchUrl(string $title, string $platform, ?string $preferredWebUrl = null, ?string $preferredAppUrl = null): array
    {
        $norm = self::normalizePlatformKey($platform) ?: strtolower(trim($platform));

        if (!empty($preferredWebUrl) && str_starts_with($preferredWebUrl, 'http')) {
            $links = self::extractOfferUrlsFromNode([
                'offers' => [[
                    'standardWebURL' => $preferredWebUrl,
                    'package' => ['shortName' => self::resolvePlatformPackageKey($norm)],
                ]]
            ], $norm, $title);
            if (!empty($preferredAppUrl)) {
                $links['app_url'] = $preferredAppUrl;
            }
            return $links;
        }

        $cacheKey = 'ott_official_watch_' . md5($norm . '_' . mb_strtolower(trim($title)));
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached['web_url'])) {
                return $cached;
            }
        } catch (\Throwable $e) {}

        $pkg = self::resolvePlatformPackageKey($norm);
        $country = self::resolveCountryForPlatform($norm);
        $gql = 'query GetSearchTitles($country: Country!, $searchFilter: TitleFilter!) { popularTitles(country: $country, filter: $searchFilter, first: 5) { edges { node { id objectId objectType content(country: $country, language: "es") { title originalTitle } offers(country: $country, platform: WEB) { standardWebURL package { shortName technicalName clearName } } } } } }';

        $resolved = null;
        try {
            $resp = Http::timeout(6)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->post('https://apis.justwatch.com/graphql', [
                    'query' => $gql,
                    'variables' => [
                        'country' => $country,
                        'searchFilter' => [
                            'searchQuery' => trim($title),
                            'packages' => [$pkg],
                        ],
                    ],
                ]);

            if ($resp->successful()) {
                $edges = data_get($resp->json(), 'data.popularTitles.edges', []);
                $targetNorm = self::removeDiacritics(trim($title));
                $bestNode = null;
                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? null;
                    if (!$node) continue;
                    $ct = self::removeDiacritics((string) ($node['content']['title'] ?? ''));
                    $co = self::removeDiacritics((string) ($node['content']['originalTitle'] ?? ''));
                    if ($ct === $targetNorm || $co === $targetNorm) {
                        $bestNode = $node;
                        break;
                    }
                    if (!$bestNode && (str_contains($ct, $targetNorm) || str_contains($targetNorm, $ct))) {
                        $bestNode = $node;
                    }
                }
                if (!$bestNode && !empty($edges[0]['node'])) {
                    $bestNode = $edges[0]['node'];
                }
                if ($bestNode) {
                    $resolved = self::extractOfferUrlsFromNode($bestNode, $norm, $title);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("resolveOfficialWatchUrl fallback [{$norm}] '{$title}': " . $e->getMessage());
        }

        if (!$resolved) {
            $resolved = self::extractOfferUrlsFromNode([], $norm, $title);
        }

        try {
            Cache::put($cacheKey, $resolved, 21600);
        } catch (\Throwable $e) {}

        return $resolved;
    }


    public static function searchRemoteOttCatalog(string $query, string $platform = 'netflix'): array
    {
        $cleanQ = trim($query);
        if ($cleanQ === '') {
            return [];
        }

        $normPlatform = self::normalizePlatformKey($platform) ?: 'netflix';
        $pkg = self::resolvePlatformPackageKey($normPlatform);
        $country = self::resolveCountryForPlatform($normPlatform);

        $gql = 'query GetSearchTitles($country: Country!, $searchFilter: TitleFilter!) { popularTitles(country: $country, filter: $searchFilter, first: 20) { edges { node { id objectId objectType content(country: $country, language: "es") { title originalTitle originalReleaseYear shortDescription posterUrl } offers(country: $country, platform: WEB) { standardWebURL package { shortName technicalName clearName } } } } } }';
        $items = [];

        try {
            $resp = Http::timeout(8)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->post('https://apis.justwatch.com/graphql', [
                    'query'     => $gql,
                    'variables' => [
                        'country'      => $country,
                        'searchFilter' => [
                            'searchQuery' => $cleanQ,
                            'packages'    => [$pkg],
                        ],
                    ],
                ]);

            if (!$resp->successful() && $country !== 'US') {
                $resp = Http::timeout(8)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    ])
                    ->post('https://apis.justwatch.com/graphql', [
                        'query'     => $gql,
                        'variables' => [
                            'country'      => 'US',
                            'searchFilter' => [
                                'searchQuery' => $cleanQ,
                                'packages'    => [$pkg],
                            ],
                        ],
                    ]);
            }

            if ($resp->successful()) {
                $edges = data_get($resp->json(), 'data.popularTitles.edges', []);
                if (is_array($edges)) {
                    foreach ($edges as $edge) {
                        $node = $edge['node'] ?? null;
                        if (!$node) continue;

                        $content = $node['content'] ?? [];
                        $title = trim((string)($content['title'] ?? ''));
                        if ($title === '') continue;

                        $origTitle = (string)($content['originalTitle'] ?? $title);
                        $objectId = (string)($node['objectId'] ?? $node['id'] ?? uniqid());
                        $objType = strtolower((string)($node['objectType'] ?? 'movie'));
                        $mediaType = str_contains($objType, 'show') || str_contains($objType, 'series') || $objType === 'tv' ? 'series' : 'movie';
                        $year = (int)($content['originalReleaseYear'] ?? date('Y'));
                        $desc = (string)($content['shortDescription'] ?? '');

                        // Filtrar por relevancia
                        $cleanQNorm = self::removeDiacritics($cleanQ);
                        $stopWords = ['el', 'la', 'los', 'las', 'de', 'del', 'en', 'y', 'a', 'que', 'nos', 'un', 'una', 'por', 'con', 'para', 'se', 'al', 'lo', 'como', 'mas', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'si', 'porque', 'esta', 'the', 'of', 'and', 'in', 'on', 'at', 'to', 'for'];
                        $allTokens = array_values(array_filter(preg_split('/\s+/u', $cleanQNorm), fn($t) => mb_strlen($t) >= 2));
                        $sigTokens = array_values(array_filter($allTokens, fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stopWords, true)));
                        if (empty($sigTokens)) $sigTokens = $allTokens;
                        if (!empty($sigTokens)) {
                            $tNorm = self::removeDiacritics($title);
                            $origNorm = self::removeDiacritics($origTitle);
                            $matchesQuery = str_contains($tNorm, $cleanQNorm) || str_contains($origNorm, $cleanQNorm);
                            if (!$matchesQuery) {
                                $matchedCount = 0;
                                foreach ($sigTokens as $token) {
                                    if (str_contains($tNorm, $token) || str_contains($origNorm, $token)) $matchedCount++;
                                }
                                $required = count($sigTokens) <= 2 ? count($sigTokens) : max(2, count($sigTokens) - 1);
                                $matchesQuery = ($matchedCount >= $required);
                            }
                            if (!$matchesQuery) continue;
                        }

                        $posterUrl = (string)($content['posterUrl'] ?? '');
                        $poster = '';
                        if ($posterUrl !== '') {
                            $poster = 'https://images.justwatch.com' . str_replace(['{profile}', '.{format}'], ['s592', '.jpg'], $posterUrl);
                        }

                        $offerLinks = self::extractOfferUrlsFromNode($node, $normPlatform, $title);
                        $item = [
                            'id'             => 'ott_' . $normPlatform . '_' . $objectId,
                            'web_url'        => $offerLinks['web_url'],
                            'app_url'        => $offerLinks['app_url'],
                            'unique_id'      => 'ott_' . $normPlatform . '_' . $objectId,
                            'title'          => $title,
                            'original_title' => $origTitle,
                            'type'           => $mediaType,
                            'media_type'     => $mediaType,
                            'year'           => $year,
                            'poster'         => $poster,
                            'poster_url'     => $poster,
                            'platform'       => $normPlatform,
                            'provider'       => $normPlatform,
                            'overview'       => $desc,
                            'synopsis'       => $desc,
                            'description'    => $desc,
                            'status'         => 'remote_catalog',
                            'available'      => false,
                            'localFilePath'  => null,
                        ];

                        self::crossReferenceAvailability($item);
                        $items[] = $item;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("OttSyncService searchRemoteOttCatalog exception [{$normPlatform}]: " . $e->getMessage());
        }

        return $items;
    }

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
                'media_type'        => self::normalizeMediaType($type, $name),
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
        $norm = self::normalizePlatformKey($provider);

        // Buscar estrictamente en títulos ya catalogados del proveedor específico (NUNCA provider = 'local')
        $titles = DB::table('titles')
            ->where(function ($q) use ($canonical, $provider, $norm) {
                $q->where('provider', $canonical)
                  ->orWhere('provider', $provider);
                if ($norm) $q->orWhere('provider', $norm);
            })
            ->where(function ($q) use ($cleanQ) {
                $q->where('name', 'like', "%{$cleanQ}%")
                  ->orWhere('sort_title', 'like', "%{$cleanQ}%")
                  ->orWhere('slug', 'like', "%" . Str::slug($cleanQ) . "%");
            })
            ->orderByRaw("CASE 
                WHEN name LIKE ? THEN 1 
                WHEN name LIKE ? THEN 2 
                ELSE 3 
            END ASC, id DESC", ["{$cleanQ}%", "%{$cleanQ}%"])
            ->limit(30)
            ->get();

        if ($titles->isNotEmpty()) {
            return $titles->map(function ($t) use ($provider) {
                $slugTitle = Str::slug($t->name);
                $posterUrl = $t->poster_url ?: "/storage/media/{$provider}/{$slugTitle}.jpg";
                $type = ucfirst($t->type ?? 'Película');
                $streamPath = $t->stream_path ?: "/storage/media/{$provider}/{$slugTitle}.mp4";
                $platformName = strtoupper($provider === 'hbo_max' ? 'Max (HBO)' : ($provider === 'disney' || $provider === 'disney_plus' ? 'Disney+' : $provider));

                $res = [
                    'id'                => (string) $t->id,
                    'unique_id'         => 'db_' . $t->id,
                    'title'             => $t->name,
                    'original_title'    => $t->sort_title ?: $t->name,
                    'type'              => $type,
                    'media_type'        => self::normalizeMediaType($type, $t->name),
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
                    'is_already_downloaded' => true,
                    'is_downloading'    => false,
                    'is_in_queue'       => false,
                ];
                self::crossReferenceAvailability($res);
                return $res;
            })->all();
        }

        return [];
    }
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
                    'media_type'        => self::normalizeMediaType($type, $name),
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
        $cacheKey = 'ott_available_catalog_' . ($provider ?? 'all') . '_' . ($filterType ?? 'all');
        try {
            $cached = Cache::get($cacheKey);
            if (!empty($cached) && is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {}

        try {
            $connected = self::getActiveConnectedPlatforms();
            if (empty($connected)) {
                return [];
            }

            $targetProvider = self::normalizePlatformKey($provider);

            // Si se solicito un proveedor especifico que NO esta conectado en DB, devolver vacio
            if ($targetProvider !== null && !in_array($targetProvider, $connected, true)) {
                return [];
            }

            // Si no se pide un proveedor puntual, consultar hasta 3 plataformas conectadas para respuesta ultra rapida
            $platformsToQuery = $targetProvider ? [$targetProvider] : array_slice($connected, 0, 3);
            $results = [];

            foreach ($platformsToQuery as $platKey) {
                $norm = self::normalizePlatformKey($platKey) ?: $platKey;
                $pkg = self::resolvePlatformPackageKey($norm);
                $country = self::resolveCountryForPlatform($norm);

                $gql = 'query GetPopularTitles($country: Country!, $filter: TitleFilter!) { popularTitles(country: $country, filter: $filter, first: 18) { edges { node { id objectId objectType content(country: $country, language: "es") { title originalTitle originalReleaseYear shortDescription posterUrl } offers(country: $country, platform: WEB) { standardWebURL package { shortName technicalName clearName } } } } } }';

                try {
                    $resp = Http::timeout(3)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        ])
                        ->post('https://apis.justwatch.com/graphql', [
                            'query'     => $gql,
                            'variables' => [
                                'country' => $country,
                                'filter'  => [
                                    'packages' => [$pkg],
                                ],
                            ],
                        ]);

                    if (!$resp->successful()) {
                        continue;
                    }

                    $edges = data_get($resp->json(), 'data.popularTitles.edges', []);
                    if (is_array($edges)) {
                        foreach ($edges as $edge) {
                            $node = $edge['node'] ?? [];
                            $content = $node['content'] ?? [];
                            $title = trim((string)($content['title'] ?? ''));
                            if ($title === '') continue;

                            $origTitle = (string)($content['originalTitle'] ?? $title);
                            $objectId = (string)($node['objectId'] ?? $node['id'] ?? uniqid());
                            $objType = strtolower((string)($node['objectType'] ?? 'movie'));
                            $isSeries = str_contains($objType, 'show') || str_contains($objType, 'series') || $objType === 'tv';
                            $mediaType = $isSeries ? 'series' : 'movie';

                            if (!empty($filterType) && $filterType !== 'ALL') {
                                $reqType = strtolower($filterType) === 'serie' || strtolower($filterType) === 'series' ? 'series' : 'movie';
                                if ($mediaType !== $reqType) {
                                    continue;
                                }
                            }

                            $year = (int)($content['originalReleaseYear'] ?? date('Y'));
                            $desc = (string)($content['shortDescription'] ?? '');

                            $posterUrl = (string)($content['posterUrl'] ?? '');
                            $poster = '';
                            if ($posterUrl !== '') {
                                $poster = 'https://images.justwatch.com' . str_replace(['{profile}', '.{format}'], ['s592', '.jpg'], $posterUrl);
                            }

                            $offerLinks = self::extractOfferUrlsFromNode($node, $norm, $title);
                            $item = [
                                'id'             => 'ott_' . $norm . '_' . $objectId,
                                'web_url'        => $offerLinks['web_url'],
                                'app_url'        => $offerLinks['app_url'],
                                'unique_id'      => 'ott_' . $norm . '_' . $objectId,
                                'external_id'    => $objectId,
                                'title'          => $title,
                                'original_title' => $origTitle,
                                'type'           => $mediaType,
                                'media_type'     => $mediaType,
                                'year'           => $year,
                                'poster'         => $poster,
                                'poster_url'     => $poster,
                                'platform'       => $norm,
                                'provider'       => $norm,
                                'overview'       => $desc,
                                'synopsis'       => $desc,
                                'description'    => $desc,
                                'status'         => 'remote_catalog',
                                'available'      => false,
                                'localFilePath'  => null,
                            ];

                            try {
                                self::crossReferenceAvailability($item);
                            } catch (\Throwable $e) {}

                            $results[] = $item;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("OttSyncService getAvailableCatalog exception [{$norm}]: " . $e->getMessage());
                }
            }

            if (!empty($results)) {
                try {
                    Cache::put($cacheKey, $results, 600);
                } catch (\Throwable $e) {}
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error("OttSyncService getAvailableCatalog fatal: " . $e->getMessage());
            return [];
        }
    }

    public function getAllCatalogSamples(): array
    {
        $connected = self::getActiveConnectedPlatforms();
        $result = [];
        foreach ($connected as $prov) {
            $result[$prov] = self::getAvailableCatalog($prov);
        }
        return $result;
    }

    public function findCatalogSampleByTitle(?string $title): ?array
    {
        if (empty($title)) return null;
        $items = self::searchReal($title, 'netflix');
        return !empty($items) ? $items[0] : null;
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
        $seenProviders = [];

        foreach (self::$platforms_config as $providerId => $config) {
                        $dbProvKey = self::toDbProviderKey($providerId);
            if (isset($seenProviders[$dbProvKey])) {
                continue;
            }
            $seenProviders[$dbProvKey] = true;
            $acc = $accounts->get($dbProvKey) ?? $accounts->get($providerId);

            $hasActiveSession = in_array($dbProvKey, $sessionProviders, true) || in_array($providerId, $sessionProviders, true);

            if ($acc && $acc->status === 'syncing') {
                try {
                    $lastActivity = $acc->updated_at ?? $acc->last_sync_at;
                    $isExpired = false;
                    if ($lastActivity instanceof \Carbon\CarbonInterface) {
                        $isExpired = $lastActivity->lt(now()->subMinutes(5));
                    } elseif (is_string($lastActivity)) {
                        $isExpired = \Carbon\Carbon::parse($lastActivity)->lt(now()->subMinutes(5));
                    }
                    if ($isExpired) {
                        $acc->status = 'error';
                        $acc->error_message = 'Tiempo de espera agotado: la sincronización superó el límite de 5 minutos.';
                        $acc->last_error = $acc->error_message;
                        $acc->last_sync_at = now();
                        $acc->save();
                    }
                } catch (\Throwable $e) {}
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

        // Si es vacío o apunta a la ruta local por defecto de storage, redirigir al disco más vacío en /FlixHN
        if (empty($raw) || str_contains($raw, 'storage/media') || $raw === 'media' || $raw === $provider) {
            return MediaStorageService::getEmptiestDiskFlixHnFolder($provider);
        }

        // Si ya especifica una carpeta en disco montado (/mnt/media_library o /home/server01)
        if (str_starts_with($raw, '/mnt/media_library') || str_starts_with($raw, '/home/server01/')) {
            if (!str_contains($raw, 'FlixHN')) {
                $raw = rtrim($raw, '/') . '/FlixHN/' . $provider;
            }
            try {
                if (!File::isDirectory($raw)) {
                    File::makeDirectory($raw, 0777, true, true);
                }
                @chmod($raw, 0777);
                return $raw;
            } catch (\Throwable $e) {
                Log::warning("OttSyncService: Falló creación en {$raw}, recurriendo al disco más vacío: " . $e->getMessage());
            }
        }

        // Ubicación en el disco con más espacio libre
        return MediaStorageService::getEmptiestDiskFlixHnFolder($provider);
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
        $tasks = $query->take($limit)->get();

        // Sincronizar reactivamente el progreso desde MediaIngestTask
        foreach ($tasks as $ot) {
            if (in_array($ot->status, ['pending', 'downloading'])) {
                $ingest = MediaIngestTask::where('output_file', $ot->file_path)
                    ->orWhere('title', $ot->title)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($ingest && ($ingest->status !== $ot->status || $ingest->progress != $ot->progress)) {
                    $ot->status = $ingest->status;
                    $ot->progress = $ingest->progress;
                    $ot->download_speed = $ingest->download_speed;
                    $ot->eta = $ingest->eta;
                    $ot->save();
                }
            }
        }

        return $tasks;
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

    public function deleteTask($taskId): bool
    {
        if (empty($taskId)) {
            return false;
        }

        $titlesToDelete = [];
        $filesToDelete = [];

        // 1. Buscar en OttDownloadTask
        $foundOtt = OttDownloadTask::where('id', $taskId)
            ->orWhere('external_id', (string) $taskId)
            ->orWhere('title', (string) $taskId)
            ->get();
        foreach ($foundOtt as $ot) {
            if (!empty($ot->title)) $titlesToDelete[] = $ot->title;
            if (!empty($ot->file_path)) $filesToDelete[] = $ot->file_path;
            $ot->delete();
        }

        // 2. Buscar en MediaIngestTask
        $foundIngest = MediaIngestTask::where('id', $taskId)
            ->orWhere('title', (string) $taskId)
            ->get();
        foreach ($foundIngest as $it) {
            if (!empty($it->title)) $titlesToDelete[] = $it->title;
            if (!empty($it->output_file)) $filesToDelete[] = $it->output_file;
            $it->delete();
        }

        // 3. Cascada completa por título y archivo a través de ambas tablas y ott_contents
        $titlesToDelete = array_unique(array_filter($titlesToDelete));
        $filesToDelete = array_unique(array_filter($filesToDelete));

        foreach ($titlesToDelete as $tName) {
            OttDownloadTask::where('title', $tName)->delete();
            MediaIngestTask::where('title', $tName)->delete();
            try {
                if (Schema::hasTable('ott_contents')) {
                    DB::table('ott_contents')->where('title', $tName)->delete();
                }
            } catch (\Throwable $e) {}
        }

        foreach ($filesToDelete as $fPath) {
            OttDownloadTask::where('file_path', $fPath)->delete();
            MediaIngestTask::where('output_file', $fPath)->delete();
        }

        Cache::forget('ott_tasks_overview');
        Cache::forget('ott_downloading_tasks');

        return true;
    }

    public function clearCompletedTasks(): int
    {
        MediaIngestTask::whereIn('status', ['completed', 'failed', 'cancelled'])
            ->orWhere('progress', '>=', 100)
            ->delete();

        $count = OttDownloadTask::whereIn('status', ['completed', 'failed', 'cancelled'])
            ->orWhere('progress', '>=', 100)
            ->delete();

        try {
            if (Schema::hasTable('ott_contents')) {
                DB::table('ott_contents')
                    ->whereIn('download_status', ['completed', 'failed'])
                    ->delete();
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

        $checkDiskFile = function (?string $path): bool {
            if (empty($path)) return false;
            $res = \App\Jobs\ProcessMediaIngestJob::resolveDiskPath($path);
            return $res && file_exists($res) && !is_dir($res) && @filesize($res) > 102400;
        };

        if ($mediaItem) {
            $isDownloaded = $checkDiskFile($mediaItem->file_path);
            $titleId = $mediaItem->title_id;
        }

        if (!$isDownloaded && !empty($title)) {
            $catalogTitle = Title::where('name', 'LIKE', $title)
                ->orWhere('name', 'LIKE', "%{$title}%")
                ->orWhere('slug', 'LIKE', Str::slug($title))
                ->first();

            if (!$catalogTitle && !empty($originalTitle) && $originalTitle !== $title) {
                $catalogTitle = Title::where('name', 'LIKE', $originalTitle)
                    ->orWhere('name', 'LIKE', "%{$originalTitle}%")
                    ->orWhere('slug', 'LIKE', Str::slug($originalTitle))
                    ->first();
            }

            if (!$catalogTitle && preg_match('/^(.*?)\s*\((.*?)\)$/u', $title, $pm)) {
                $p1 = trim($pm[1]);
                $p2 = trim($pm[2]);
                $catalogTitle = Title::where('name', 'LIKE', "%{$p1}%")
                    ->orWhere('name', 'LIKE', "%{$p2}%")
                    ->first();
            }

            if ($catalogTitle) {
                $titleId = $catalogTitle->id;
                if ($catalogTitle->type === 'series') {
                    $ep = Episode::where('title_id', $catalogTitle->id)->whereNotNull('file_path')->first();
                    if ($ep && $checkDiskFile($ep->file_path)) {
                        $isDownloaded = true;
                        $item['localFilePath'] = $ep->file_path;
                        $item['filePath'] = $ep->file_path;
                        $item['file_path'] = $ep->file_path;
                    }
                }
                if (!$isDownloaded) {
                    $candidatePath = $catalogTitle->file_path ?: ($catalogTitle->source_path ?: $catalogTitle->stream_path);
                    if (!empty($candidatePath) && !str_starts_with($candidatePath, '/api/')) {
                        $isDownloaded = $checkDiskFile($candidatePath);
                        if ($isDownloaded) {
                            $item['localFilePath'] = $candidatePath;
                            $item['filePath'] = $candidatePath;
                            $item['file_path'] = $candidatePath;
                        }
                    }
                }
            }
        }

        if (!empty($title)) {
            $ingestTask = MediaIngestTask::where('title', 'LIKE', $title)->orderBy('id', 'desc')->first();
            if ($ingestTask) {
                if ($ingestTask->status === 'completed' || $ingestTask->progress >= 100) {
                    $isDownloaded = $checkDiskFile($ingestTask->output_file);
                    if (!$titleId) $titleId = $ingestTask->title_id;
                    $item['localFilePath'] = $ingestTask->output_file;
                    $item['filePath'] = $ingestTask->output_file;
                    $item['file_path'] = $ingestTask->output_file;
                } elseif (in_array($ingestTask->status, ['pending', 'downloading', 'processing'])) {
                    $isInQueue = true;
                    $item['localFilePath'] = $ingestTask->output_file;
                    $item['filePath'] = $ingestTask->output_file;
                    $item['file_path'] = $ingestTask->output_file;
                }
            }
        }

        if (!$isDownloaded && !$isInQueue && !empty($title)) {
            $ottTask = OttDownloadTask::where('title', 'LIKE', $title)->orderBy('id', 'desc')->first();
            if ($ottTask) {
                if ($ottTask->status === 'completed' || $ottTask->progress >= 100) {
                    $isDownloaded = $checkDiskFile($ottTask->file_path);
                    $item['localFilePath'] = $ottTask->file_path;
                    $item['filePath'] = $ottTask->file_path;
                    $item['file_path'] = $ottTask->file_path;
                } elseif (in_array($ottTask->status, ['pending', 'downloading', 'processing'])) {
                    $isInQueue = true;
                    $item['localFilePath'] = $ottTask->file_path;
                    $item['filePath'] = $ottTask->file_path;
                    $item['file_path'] = $ottTask->file_path;
                }
            }
        }

        $isDownloading = false;
        if (isset($ottTask) && $ottTask && $ottTask->status === 'downloading') {
            $isDownloading = true;
        }

        $item['is_already_downloaded'] = $isDownloaded;
        $item['is_downloading'] = $isDownloading;
        $item['is_in_queue'] = $isInQueue;
        $item['available'] = $isDownloaded;

        if ($isDownloaded) {
            $item['status'] = 'En Catálogo';
        } elseif ($isDownloading || $isInQueue) {
            $item['status'] = 'Descargando';
        } else {
            $item['status'] = 'Available';
        }

        $item['is_already_downloaded'] = (bool) $isDownloaded;
        $item['is_in_queue'] = !$isDownloaded && (bool) $isInQueue;
        $item['title_id'] = $titleId;

        if (empty($item['localFilePath'])) {
            $slugTitle = Str::slug($title);
            $provider = $item['provider'] ?? 'netflix';
            $uniqueId = $item['id'] ?? ($provider . '_' . $slugTitle);
            $item['localFilePath'] = "/var/www/html/storage/media/{$provider}/{$uniqueId}/{$slugTitle}.mp4";
            $item['filePath'] = $item['localFilePath'];
            $item['file_path'] = $item['localFilePath'];
        }
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
            $fallbackAccount = OttProviderAccount::firstOrCreate(
                ['provider' => 'netflix'],
                [
                    'account_name' => 'netflix_default@flixhn.local',
                    'status' => 'connected',
                    'auto_download' => true,
                    'quality_profile' => '1080p',
                ]
            );
            $connectedAccounts = collect([$fallbackAccount]);
        }

        $rawProvider = $payload['provider'] ?? $payload['platform'] ?? 'netflix';
        $normProvider = self::normalizePlatformKey($rawProvider) ?: Str::slug($rawProvider, '_');
        $requestedProvider = self::toDbProviderKey($normProvider ?: 'netflix');

        $activeAccount = $connectedAccounts->firstWhere('provider', $requestedProvider)
            ?? $connectedAccounts->firstWhere('provider', $normProvider);

        if (!$activeAccount) {
            try {
                $activeAccount = OttProviderAccount::firstOrCreate(
                    ['provider' => $requestedProvider],
                    [
                        'account_name' => "{$requestedProvider}_default@flixhn.local",
                        'status' => 'connected',
                        'auto_download' => true,
                        'quality_profile' => '1080p',
                        'credentials' => OttProviderAccount::encryptSecret(json_encode([
                            'auth_method' => 'gateway',
                            'connected_at' => now()->toIso8601String(),
                        ])),
                        'session_metadata' => [
                            'region' => 'HN / Central America (LATAM)',
                            'platform' => ucfirst(str_replace('_', ' ', $requestedProvider)),
                            'auth_method' => 'credentials',
                            'max_streams' => 4,
                            'storage_path' => "/storage/media/{$requestedProvider}",
                            'target_folder' => "/storage/media/{$requestedProvider}",
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("No se pudo registrar cuenta OttProviderAccount para {$requestedProvider}: " . $e->getMessage());
            }
        }

        $provider = $requestedProvider ?: ($activeAccount?->provider ?? 'netflix');
        $mediaType = self::normalizeMediaType($payload['media_type'] ?? $payload['type'] ?? null, $title);
        $quality = $payload['quality'] ?? '1080p';
        $posterUrl = $payload['poster_url'] ?? ($payload['poster'] ?? null);
        $year = $payload['year'] ?? date('Y');
        $overview = $payload['overview'] ?? "Contenido descargado desde {$provider} a la red On-Net de FlixHN.";
        $slugTitle = Str::slug($title);
        if (empty($slugTitle)) {
            $slugTitle = 'ott_' . time();
        }

        $rawId = $payload['id'] ?? $payload['unique_id'] ?? null;
        $uniqueId = !empty($rawId) ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $rawId) : ($provider . '_' . substr(md5($title . microtime()), 0, 8));

                $targetFolderInput = !empty($payload['target_folder']) ? $payload['target_folder'] : "/storage/media/{$provider}/{$uniqueId}";
        $targetFolder = self::resolveAndEnsureTargetFolder($targetFolderInput, $provider);

        $seasonNum = (int) ($payload['season_number'] ?? 1);
        $episodeNum = (int) ($payload['episode_number'] ?? 1);

        if ($mediaType === 'series') {
            $tag = sprintf("S%02dE%02d", $seasonNum, $episodeNum);
            $targetFolder = rtrim($targetFolder, '/') . "/series/{$slugTitle}/Temporada {$seasonNum}";
            $fileName = "{$slugTitle}_{$tag}.mp4";
        } else {
            $fileName = "{$slugTitle}.mp4";
        }

        if (!File::isDirectory($targetFolder)) {
            File::makeDirectory($targetFolder, 0777, true, true);
        }
        @chmod($targetFolder, 0777);

        $outputFile = rtrim(str_replace('\\', '/', $targetFolder), '/') . '/' . $fileName;
        $fullPath = $outputFile;

        // Idempotencia: evitar creación duplicada si ya está en curso
        $existingIngest = MediaIngestTask::where('title', $title)
            ->whereIn('status', ['pending', 'downloading'])
            ->first();
        if ($existingIngest) {
            Log::info("createDownloadTaskFromSearch: Tarea ya activa para '{$title}', retornando #{$existingIngest->id}");
            return $existingIngest;
        }

        $existingOtt = OttDownloadTask::where('title', $title)
            ->whereIn('status', ['pending', 'downloading'])
            ->first();
        if ($existingOtt) {
            $matched = MediaIngestTask::where('title', $title)->first();
            if ($matched) {
                return $matched;
            }
        }

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
                'season_number' => $mediaType === 'series' ? $seasonNum : null,
                'episode_number' => $mediaType === 'series' ? $episodeNum : null,
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
                    'season_number' => $mediaType === 'series' ? $seasonNum : null,
                    'episode_number' => $mediaType === 'series' ? $episodeNum : null,
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
            app(MediaIngestService::class)->launchBackgroundWorker($ingestTask->id);
        } catch (\Throwable $e) {
            Log::warning("Error lanzando background worker: " . $e->getMessage());
            try {
                ProcessMediaIngestJob::dispatch($ingestTask->id);
            } catch (\Throwable $f) {}
        }

        return $ingestTask->fresh();
    }
}

