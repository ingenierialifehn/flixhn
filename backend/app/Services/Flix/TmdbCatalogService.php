<?php

namespace AppServicesFlix;

use AppModelsPlugin;
use CarbonCarbon;
use IlluminateSupportFacadesCache;
use IlluminateSupportFacadesHttp;
use IlluminateSupportFacadesLog;

/**
 * Motor TMDB portado desde plugin.video.flix (lib/tmdb.py y lib/navigation.py).
 */
class TmdbCatalogService
{
    protected const CACHE_PREFIX = 'flix_tmdb_v1:';
    protected const CACHE_REGISTRY_KEY = 'flix_tmdb_v1:keys';

    protected string $baseUrl;
    protected string $imageBaseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('flix.tmdb.base_url', 'https://api.themoviedb.org/3'), '/');
        $this->imageBaseUrl = rtrim((string) config('flix.tmdb.image_base_url', 'https://image.tmdb.org/t/p'), '/') . '/';
        $this->timeout = (int) config('flix.tmdb.timeout', 10);
    }

    public function getSettings(): array
    {
        $defaults = [
            'api_key' => (string) config('flix.tmdb.api_key', ''),
            'language' => (string) config('flix.tmdb.language', 'es-ES'),
            'fallback_language' => (string) config('flix.tmdb.fallback_language', 'en-US'),
            'include_adult' => (bool) config('flix.tmdb.include_adult', false),
            'prefer_original_titles' => (bool) config('flix.tmdb.prefer_original_titles', false),
            'cache_enabled' => (bool) config('flix.tmdb.cache_enabled', true),
            'cache_ttl_days' => (int) config('flix.tmdb.cache_ttl_days', 7),
        ];

        try {
            $plugin = Plugin::where('slug', 'themoviedb')->where('is_installed', true)->first();
            if ($plugin && is_array($plugin->settings)) {
                $pluginKey = trim((string) ($plugin->settings['api_key'] ?? ''));
                if ($pluginKey !== '' && !str_starts_with($pluginKey, 'tmdb_live_key_')) {
                    $defaults['api_key'] = $pluginKey;
                }
                if (!empty($plugin->settings['language'])) {
                    $defaults['language'] = (string) $plugin->settings['language'];
                }
                if (array_key_exists('include_adult', $plugin->settings)) {
                    $defaults['include_adult'] = (bool) $plugin->settings['include_adult'];
                }
                if (!empty($plugin->settings['cache_ttl_days'])) {
                    $defaults['cache_ttl_days'] = max(1, (int) $plugin->settings['cache_ttl_days']);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('TmdbCatalogService::getSettings fallback to config: ' . $e->getMessage());
        }

        return $defaults;
    }

    public function hasValidApiKey(): bool
    {
        return trim($this->getSettings()['api_key']) !== '';
    }

    public function request(string $path, array $params = [], bool $useCache = true): array
    {
        $settings = $this->getSettings();
        $apiKey = trim($settings['api_key']);

        if ($apiKey === '') {
            throw new \RuntimeException(
                'TMDB API Key no configurada. Configúrala en Plugins -> TheMovieDB (TMDb) o mediante TMDB_API_KEY en .env.'
            );
        }

        $cleanPath = '/' . ltrim($path, '/');
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        $params['language'] = $params['language'] ?? $settings['language'];
        ksort($params);

        $cacheEnabled = $useCache && $settings['cache_enabled'];
        $cacheKey = self::CACHE_PREFIX . hash('sha256', $cleanPath . '|' . json_encode($params));

        if ($cacheEnabled) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $queryParams = array_merge($params, ['api_key' => $apiKey]);
        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->get($this->baseUrl . $cleanPath, $queryParams);

        if (!$response->successful()) {
            $msg = $response->json('status_message') ?? ('HTTP ' . $response->status());
            throw new \RuntimeException("Error en API TMDB [{$cleanPath}]: {$msg}");
        }

        $data = $response->json();
        if (!is_array($data)) {
            $data = [];
        }

        if ($cacheEnabled) {
            $ttl = now()->addDays(max(1, (int) $settings['cache_ttl_days']));
            Cache::put($cacheKey, $data, $ttl);
            $this->trackCacheKey($cacheKey);
        }

        return $data;
    }

    public function clearCache(): int
    {
        $keys = Cache::get(self::CACHE_REGISTRY_KEY, []);
        $count = 0;

        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (Cache::forget($key)) {
                    $count++;
                }
            }
        }

        Cache::forget(self::CACHE_REGISTRY_KEY);
        return $count;
    }

    protected function trackCacheKey(string $key): void
    {
        try {
            $keys = Cache::get(self::CACHE_REGISTRY_KEY, []);
            if (!is_array($keys)) {
                $keys = [];
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                if (count($keys) > 2000) {
                    $keys = array_slice($keys, -2000);
                }
                Cache::put(self::CACHE_REGISTRY_KEY, $keys, now()->addDays(30));
            }
        } catch (\Throwable $e) {
            // No bloquear la respuesta si falla el tracking de claves
        }
    }

    public function getGenres(string $mediaType = 'movie'): array
    {
        $endpoint = ($mediaType === 'tv' || $mediaType === 'series') ? '/genre/tv/list' : '/genre/movie/list';
        $data = $this->request($endpoint);
        return $data['genres'] ?? [];
    }

    public function discoverMovies(array $filters = []): array
    {
        $settings = $this->getSettings();
        $params = [
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'include_adult' => isset($filters['include_adult'])
                ? ($filters['include_adult'] ? 'true' : 'false')
                : ($settings['include_adult'] ? 'true' : 'false'),
            'sort_by' => $filters['sort_by'] ?? 'popularity.desc',
            'with_genres' => $filters['with_genres'] ?? null,
            'primary_release_year' => $filters['year'] ?? ($filters['primary_release_year'] ?? null),
        ];

        $data = $this->request('/discover/movie', $params);
        return $this->formatPaginatedList($data, 'movie');
    }

    public function discoverShows(array $filters = []): array
    {
        $settings = $this->getSettings();
        $params = [
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'include_adult' => isset($filters['include_adult'])
                ? ($filters['include_adult'] ? 'true' : 'false')
                : ($settings['include_adult'] ? 'true' : 'false'),
            'sort_by' => $filters['sort_by'] ?? 'popularity.desc',
            'with_genres' => $filters['with_genres'] ?? null,
            'first_air_date_year' => $filters['year'] ?? ($filters['first_air_date_year'] ?? null),
        ];

        $data = $this->request('/discover/tv', $params);
        return $this->formatPaginatedList($data, 'series');
    }

    public function trending(string $mediaType = 'movie', string $timeWindow = 'week', int $page = 1): array
    {
        $tmdbType = ($mediaType === 'series' || $mediaType === 'tv') ? 'tv' : ($mediaType === 'all' ? 'all' : 'movie');
        $window = in_array($timeWindow, ['day', 'week'], true) ? $timeWindow : 'week';

        $data = $this->request("/trending/{$tmdbType}/{$window}", ['page' => max(1, $page)]);
        return $this->formatPaginatedList($data, $tmdbType === 'tv' ? 'series' : ($tmdbType === 'movie' ? 'movie' : null));
    }

    public function listMovies(string $category = 'popular', int $page = 1): array
    {
        $allowed = ['popular', 'top_rated', 'now_playing', 'upcoming'];
        $cat = in_array($category, $allowed, true) ? $category : 'popular';
        $data = $this->request("/movie/{$cat}", ['page' => max(1, $page)]);
        return $this->formatPaginatedList($data, 'movie');
    }

    public function listShows(string $category = 'popular', int $page = 1): array
    {
        $allowed = ['popular', 'top_rated', 'airing_today', 'on_the_air'];
        $cat = in_array($category, $allowed, true) ? $category : 'popular';
        $data = $this->request("/tv/{$cat}", ['page' => max(1, $page)]);
        return $this->formatPaginatedList($data, 'series');
    }

    public function search(string $type, string $query, int $page = 1): array
    {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return ['page' => 1, 'total_pages' => 0, 'total_results' => 0, 'results' => []];
        }

        $settings = $this->getSettings();
        $tmdbType = match (strtolower($type)) {
            'tv', 'show', 'shows', 'series' => 'tv',
            'person', 'people' => 'person',
            'multi', 'all' => 'multi',
            default => 'movie',
        };

        $data = $this->request("/search/{$tmdbType}", [
            'query' => $cleanQuery,
            'page' => max(1, $page),
            'include_adult' => $settings['include_adult'] ? 'true' : 'false',
        ]);

        if ($tmdbType === 'person') {
            return [
                'page' => (int) ($data['page'] ?? 1),
                'total_pages' => (int) ($data['total_pages'] ?? 1),
                'total_results' => (int) ($data['total_results'] ?? 0),
                'results' => array_map(fn ($p) => [
                    'id' => $p['id'],
                    'tmdb_id' => $p['id'],
                    'type' => 'person',
                    'name' => $p['name'] ?? '',
                    'known_for_department' => $p['known_for_department'] ?? '',
                    'profile_url' => $this->imageUrl($p['profile_path'] ?? null, 'w500'),
                    'popularity' => $p['popularity'] ?? 0,
                ], $data['results'] ?? []),
            ];
        }

        $forcedType = $tmdbType === 'multi' ? null : ($tmdbType === 'tv' ? 'series' : 'movie');
        return $this->formatPaginatedList($data, $forcedType);
    }

    public function getSimilar(string $mediaType, int $tmdbId, int $page = 1): array
    {
        $isSeries = in_array($mediaType, ['tv', 'show', 'series'], true);
        $endpoint = $isSeries ? "/tv/{$tmdbId}/similar" : "/movie/{$tmdbId}/similar";
        $data = $this->request($endpoint, ['page' => max(1, $page)]);
        return $this->formatPaginatedList($data, $isSeries ? 'series' : 'movie');
    }

    public function getPersonCredits(int $personId): array
    {
        $data = $this->request("/person/{$personId}/combined_credits");
        $now = Carbon::now();
        $entries = [];

        foreach ($data['cast'] ?? [] as $credit) {
            $mType = $credit['media_type'] ?? '';
            if (!in_array($mType, ['movie', 'tv'], true)) {
                continue;
            }
            $releaseDate = $mType === 'movie'
                ? ($credit['release_date'] ?? '')
                : ($credit['first_air_date'] ?? '');

            if ($this->isUnaired($releaseDate, $now)) {
                continue;
            }

            $entries[] = $this->normalizeListItem($credit, $mType === 'tv' ? 'series' : 'movie');
        }

        usort($entries, static fn ($a, $b) => strcmp((string) ($b['release_date'] ?? ''), (string) ($a['release_date'] ?? '')));

        return $entries;
    }

    public function getMovieDetails(int $movieId): array
    {
        $settings = $this->getSettings();
        $data = $this->request("/movie/{$movieId}", [
            'append_to_response' => 'credits,alternative_titles,videos,external_ids',
        ]);

        $altTitles = [];
        foreach ($data['alternative_titles']['titles'] ?? [] as $alt) {
            if (!empty($alt['iso_3166_1']) && !empty($alt['title'])) {
                $altTitles[strtolower($alt['iso_3166_1'])] = $alt['title'];
            }
        }

        $displayTitle = ($data['title'] ?? '') ?: ($data['original_title'] ?? '');
        $altTitles['auto'] = $displayTitle;
        if ($settings['prefer_original_titles'] && !empty($data['original_title'])) {
            $displayTitle = $data['original_title'];
        }

        $premiered = $this->sanitizeDate($data['release_date'] ?? '');
        $cast = $data['credits']['cast'] ?? [];
        $crew = $data['credits']['crew'] ?? [];
        $runtime = isset($data['runtime']) ? (int) $data['runtime'] : null;

        return [
            'tmdb_id' => (int) $data['id'],
            'imdb_id' => $data['imdb_id'] ?? ($data['external_ids']['imdb_id'] ?? null),
            'type' => 'movie',
            'title' => $displayTitle,
            'original_title' => $data['original_title'] ?? $displayTitle,
            'alternative_titles' => $altTitles,
            'tagline' => $data['tagline'] ?? '',
            'overview' => $data['overview'] ?? '',
            'status' => $data['status'] ?? '',
            'release_date' => $premiered,
            'release_year' => $premiered ? (int) substr($premiered, 0, 4) : null,
            'rating' => isset($data['vote_average']) ? round((float) $data['vote_average'], 1) : null,
            'votes' => (int) ($data['vote_count'] ?? 0),
            'duration_seconds' => $runtime ? $runtime * 60 : null,
            'genres' => array_values(array_map(static fn ($g) => $g['name'], $data['genres'] ?? [])),
            'studios' => array_values(array_map(static fn ($s) => $s['name'], $data['production_companies'] ?? [])),
            'countries' => $data['origin_country'] ?? array_map(static fn ($c) => $c['iso_3166_1'], $data['production_countries'] ?? []),
            'cast' => $this->formatCast($cast),
            'directors' => $this->filterCrewByJob($crew, 'Director'),
            'writers' => $this->filterCrewByJob($crew, 'Writer'),
            'poster_url' => $this->imageUrl($data['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->imageUrl($data['backdrop_path'] ?? null, 'original'),
            'trailer_youtube_key' => $this->extractYoutubeTrailerKey($data['videos']['results'] ?? []),
        ];
    }

    public function getShowDetails(int $showId, bool $includeUnaired = true): array
    {
        $settings = $this->getSettings();
        $data = $this->request("/tv/{$showId}", [
            'append_to_response' => 'credits,alternative_titles,external_ids,videos',
        ]);

        $altTitles = [];
        foreach ($data['alternative_titles']['results'] ?? [] as $alt) {
            if (!empty($alt['iso_3166_1']) && !empty($alt['title'])) {
                $altTitles[strtolower($alt['iso_3166_1'])] = $alt['title'];
            }
        }

        $displayTitle = ($data['name'] ?? '') ?: ($data['original_name'] ?? '');
        $altTitles['auto'] = $displayTitle;
        if ($settings['prefer_original_titles'] && !empty($data['original_name'])) {
            $displayTitle = $data['original_name'];
        }

        $premiered = $this->sanitizeDate($data['first_air_date'] ?? '');
        $cast = $data['credits']['cast'] ?? [];
        $crew = $data['credits']['crew'] ?? [];
        $now = Carbon::now();

        $seasons = [];
        foreach ($data['seasons'] ?? [] as $season) {
            $airDate = $this->sanitizeDate($season['air_date'] ?? '');
            if (!$includeUnaired && $this->isUnaired($airDate, $now)) {
                continue;
            }
            $seasons[] = [
                'tmdb_id' => $season['id'] ?? null,
                'season_number' => (int) ($season['season_number'] ?? 0),
                'title' => $season['name'] ?? ('Temporada ' . ($season['season_number'] ?? 0)),
                'overview' => $season['overview'] ?? '',
                'air_date' => $airDate,
                'episode_count' => (int) ($season['episode_count'] ?? 0),
                'poster_url' => $this->imageUrl($season['poster_path'] ?? null, 'w780')
                    ?: $this->imageUrl($data['poster_path'] ?? null, 'w780'),
            ];
        }

        return [
            'tmdb_id' => (int) $data['id'],
            'imdb_id' => $data['external_ids']['imdb_id'] ?? null,
            'tvdb_id' => isset($data['external_ids']['tvdb_id']) ? (string) $data['external_ids']['tvdb_id'] : null,
            'type' => 'series',
            'title' => $displayTitle,
            'original_title' => $data['original_name'] ?? $displayTitle,
            'alternative_titles' => $altTitles,
            'tagline' => $data['tagline'] ?? '',
            'overview' => $data['overview'] ?? '',
            'status' => $data['status'] ?? '',
            'release_date' => $premiered,
            'release_year' => $premiered ? (int) substr($premiered, 0, 4) : null,
            'rating' => isset($data['vote_average']) ? round((float) $data['vote_average'], 1) : null,
            'votes' => (int) ($data['vote_count'] ?? 0),
            'genres' => array_values(array_map(static fn ($g) => $g['name'], $data['genres'] ?? [])),
            'studios' => array_values(array_map(static fn ($s) => $s['name'], $data['production_companies'] ?? [])),
            'countries' => $data['origin_country'] ?? [],
            'cast' => $this->formatCast($cast),
            'directors' => $this->filterCrewByJob($crew, 'Director'),
            'writers' => $this->filterCrewByJob($crew, 'Writer'),
            'poster_url' => $this->imageUrl($data['poster_path'] ?? null, 'w780'),
            'backdrop_url' => $this->imageUrl($data['backdrop_path'] ?? null, 'original'),
            'trailer_youtube_key' => $this->extractYoutubeTrailerKey($data['videos']['results'] ?? []),
            'seasons' => $seasons,
        ];
    }

    public function getSeasonDetails(int $showId, int $seasonNumber, bool $includeUnaired = true): array
    {
        $data = $this->request("/tv/{$showId}/season/{$seasonNumber}", [
            'append_to_response' => 'credits,videos',
        ]);

        $seasonCast = $data['credits']['cast'] ?? [];
        $now = Carbon::now();
        $episodes = [];

        foreach ($data['episodes'] ?? [] as $ep) {
            $airDate = $this->sanitizeDate($ep['air_date'] ?? '');
            if (!$includeUnaired && $this->isUnaired($airDate, $now)) {
                continue;
            }
            $epCrew = $ep['crew'] ?? [];
            $epCast = array_merge($seasonCast, $ep['guest_stars'] ?? []);
            $runtime = isset($ep['runtime']) ? (int) $ep['runtime'] : null;

            $episodes[] = [
                'tmdb_id' => $ep['id'] ?? null,
                'season_number' => $seasonNumber,
                'episode_number' => (int) ($ep['episode_number'] ?? 0),
                'title' => $ep['name'] ?? ('Episodio ' . ($ep['episode_number'] ?? 0)),
                'overview' => $ep['overview'] ?? '',
                'air_date' => $airDate,
                'duration_seconds' => $runtime ? $runtime * 60 : null,
                'rating' => isset($ep['vote_average']) ? round((float) $ep['vote_average'], 1) : null,
                'votes' => (int) ($ep['vote_count'] ?? 0),
                'production_code' => $ep['production_code'] ?? null,
                'thumbnail_url' => $this->imageUrl($ep['still_path'] ?? null, 'w780'),
                'cast' => $this->formatCast($epCast),
                'directors' => $this->filterCrewByJob($epCrew, 'Director'),
                'writers' => $this->filterCrewByJob($epCrew, 'Writer'),
            ];
        }

        return [
            'show_id' => $showId,
            'season_number' => $seasonNumber,
            'title' => $data['name'] ?? "Temporada {$seasonNumber}",
            'overview' => $data['overview'] ?? '',
            'air_date' => $this->sanitizeDate($data['air_date'] ?? ''),
            'poster_url' => $this->imageUrl($data['poster_path'] ?? null, 'w780'),
            'trailer_youtube_key' => $this->extractYoutubeTrailerKey($data['videos']['results'] ?? []),
            'episodes' => $episodes,
        ];
    }

    public function getTrailer(
        string $mediaType,
        int $tmdbId,
        ?int $seasonNumber = null,
        ?int $episodeNumber = null,
        ?string $language = null
    ): ?array {
        $settings = $this->getSettings();
        $lang = $language ?? $settings['language'];
        $fallback = $settings['fallback_language'];

        $path = match (strtolower($mediaType)) {
            'movie' => "/movie/{$tmdbId}/videos",
            'show', 'tv', 'series' => "/tv/{$tmdbId}/videos",
            'season' => "/tv/{$tmdbId}/season/" . (int) $seasonNumber . '/videos',
            'episode' => "/tv/{$tmdbId}/season/" . (int) $seasonNumber . '/episode/' . (int) $episodeNumber . '/videos',
            default => throw new \InvalidArgumentException("Tipo de medio inválido para tráiler: {$mediaType}"),
        };

        $data = $this->request($path, ['language' => $lang]);
        $key = $this->extractYoutubeTrailerKey($data['results'] ?? []);

        if ($key !== null) {
            return [
                'youtube_key' => $key,
                'youtube_url' => "https://www.youtube.com/watch?v={$key}",
                'embed_url' => "https://www.youtube.com/embed/{$key}",
                'language' => $lang,
            ];
        }

        if ($lang !== $fallback) {
            return $this->getTrailer($mediaType, $tmdbId, $seasonNumber, $episodeNumber, $fallback);
        }

        return null;
    }

    protected function formatPaginatedList(array $data, ?string $forcedType): array
    {
        $items = [];
        foreach ($data['results'] ?? [] as $row) {
            $rowType = $forcedType;
            if ($rowType === null) {
                $mType = $row['media_type'] ?? 'movie';
                if ($mType === 'person') {
                    continue;
                }
                $rowType = $mType === 'tv' ? 'series' : 'movie';
            }
            $items[] = $this->normalizeListItem($row, $rowType);
        }

        return [
            'page' => (int) ($data['page'] ?? 1),
            'total_pages' => (int) ($data['total_pages'] ?? 1),
            'total_results' => (int) ($data['total_results'] ?? count($items)),
            'results' => $items,
        ];
    }

    protected function normalizeListItem(array $row, string $mediaType): array
    {
        $isSeries = $mediaType === 'series';
        $title = $isSeries
            ? (($row['name'] ?? '') ?: ($row['original_name'] ?? ''))
            : (($row['title'] ?? '') ?: ($row['original_title'] ?? ''));
        $origTitle = $isSeries ? ($row['original_name'] ?? $title) : ($row['original_title'] ?? $title);
        $date = $this->sanitizeDate($isSeries ? ($row['first_air_date'] ?? '') : ($row['release_date'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tmdb_id' => (int) ($row['id'] ?? 0),
            'type' => $mediaType,
            'media_type' => $mediaType,
            'title' => $title,
            'original_title' => $origTitle,
            'overview' => $row['overview'] ?? '',
            'release_date' => $date,
            'release_year' => $date ? (int) substr($date, 0, 4) : null,
            'rating' => isset($row['vote_average']) ? round((float) $row['vote_average'], 1) : null,
            'votes' => (int) ($row['vote_count'] ?? 0),
            'genre_ids' => $row['genre_ids'] ?? [],
            'poster_url' => $this->imageUrl($row['poster_path'] ?? null, 'w500'),
            'backdrop_url' => $this->imageUrl($row['backdrop_path'] ?? null, 'w1280'),
        ];
    }

    public function imageUrl(?string $path, string $size = 'original'): ?string
    {
        if (empty($path)) {
            return null;
        }
        return $this->imageBaseUrl . $size . $path;
    }

    protected function formatCast(array $cast): array
    {
        $formatted = [];
        foreach (array_slice($cast, 0, 25) as $idx => $member) {
            $formatted[] = [
                'name' => $member['name'] ?? '-',
                'role' => $member['character'] ?? '',
                'thumbnail' => $this->imageUrl($member['profile_path'] ?? null, 'w342'),
                'order' => $idx + 1,
            ];
        }
        return $formatted;
    }

    protected function filterCrewByJob(array $crew, string $job): array
    {
        $names = [];
        foreach ($crew as $c) {
            if (($c['job'] ?? '') === $job && !empty($c['name'])) {
                $names[] = $c['name'];
            }
        }
        return array_values(array_unique($names));
    }

    protected function extractYoutubeTrailerKey(array $videos): ?string
    {
        foreach ($videos as $video) {
            if (($video['site'] ?? '') === 'YouTube' && ($video['type'] ?? '') === 'Trailer' && !empty($video['key'])) {
                return (string) $video['key'];
            }
        }
        foreach ($videos as $video) {
            if (($video['site'] ?? '') === 'YouTube' && !empty($video['key'])) {
                return (string) $video['key'];
            }
        }
        return null;
    }

    protected function sanitizeDate(?string $date): string
    {
        $clean = trim((string) $date);
        if ($clean === '' || in_array($clean, ['2999-01-01', '1900-01-01'], true)) {
            return '';
        }
        return $clean;
    }

    protected function isUnaired(string $airDate, Carbon $now): bool
    {
        if ($airDate === '') {
            return true;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $airDate)->startOfDay()->greaterThan($now);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
