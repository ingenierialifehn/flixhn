<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Episode;
use App\Models\MediaItem;
use App\Models\Plugin;
use App\Models\Season;
use App\Models\ServerSetting;
use App\Models\Title;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LocalMediaScannerService
{
    /**
     * Rutas predeterminadas de bibliotecas locales en el servidor.
     */
    protected array $defaultDisks = [
        '/mnt/media_library/Disk2',
        '/mnt/media_library/Disk3',
        '/mnt/media_library/Disk4',
        '/mnt/media_library/Disk5',
        '/mnt/media_library/Disk6',
        '/mnt/media_library/Disk7',
    ];

    /**
     * Carpetas y patrones a ignorar estrictamente durante el escaneo.
     */
    protected array $ignoredDirectories = [
        'lost+found',
        'backups_mysql',
        'backups_sistema',
        'bakup',
        'RESCATE_MANUAL_MYSQL',
        '@eaDir',
        '.Trash',
        '$RECYCLE.BIN',
        'System Volume Information',
        '.git',
    ];

    /**
     * Extensiones de video reconocidas.
     */
    protected array $videoExtensions = [
        'mp4',
        'mkv',
        'avi',
        'webm',
        'm4v',
    ];

    /**
     * Cache en memoria de consultas a TMDb para evitar solicitudes duplicadas en la misma ejecución.
     */
    protected array $tmdbCache = [];

    /**
     * Obtener las rutas de discos configuradas en el servidor.
     */
    public function getConfiguredPaths(): array
    {
        $custom = ServerSetting::get('local_media_paths');
        if (!empty($custom)) {
            if (is_string($custom)) {
                $decoded = json_decode($custom, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
                return array_filter(array_map('trim', explode(',', $custom)));
            }
            if (is_array($custom)) {
                return $custom;
            }
        }
        return $this->defaultDisks;
    }

    /**
     * Verificar el estado y espacio en disco de las rutas de bibliotecas locales en tiempo real.
     */
    public function getStorageStatus(): array
    {
        $paths = $this->getConfiguredPaths();
        $disks = [];
        $totalBytesAll = 0;
        $freeBytesAll = 0;
        $onlineCount = 0;

        foreach ($paths as $path) {
            $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
            $name = basename($normalizedPath) ?: $normalizedPath;
            $exists = file_exists($normalizedPath) && is_dir($normalizedPath);

            $totalBytes = 0;
            $freeBytes = 0;
            $detectedFolders = [];

            if ($exists) {
                $onlineCount++;
                $totalBytes = @disk_total_space($normalizedPath) ?: 0;
                $freeBytes = @disk_free_space($normalizedPath) ?: 0;
                $totalBytesAll += $totalBytes;
                $freeBytesAll += $freeBytes;

                // Detectar carpetas de primer nivel ignorando las carpetas del sistema
                try {
                    $entries = @scandir($normalizedPath) ?: [];
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..') continue;
                        if ($this->isIgnoredDir($entry)) continue;
                        $fullEntry = $normalizedPath . '/' . $entry;
                        if (is_dir($fullEntry)) {
                            $detectedFolders[] = $entry;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Error leyendo carpetas en {$normalizedPath}: " . $e->getMessage());
                }
            }

            $usedBytes = max(0, $totalBytes - $freeBytes);
            $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0;

            $disks[] = [
                'path' => $normalizedPath,
                'name' => $name,
                'is_mounted' => $exists,
                'status' => $exists ? 'online' : 'unmounted',
                'total_bytes' => $totalBytes,
                'free_bytes' => $freeBytes,
                'used_bytes' => $usedBytes,
                'used_percent' => $usedPercent,
                'total_formatted' => $this->formatBytes($totalBytes),
                'free_formatted' => $this->formatBytes($freeBytes),
                'used_formatted' => $this->formatBytes($usedBytes),
                'detected_folders' => $detectedFolders,
                'folders_count' => count($detectedFolders),
            ];
        }

        $usedBytesAll = max(0, $totalBytesAll - $freeBytesAll);
        $usedPercentAll = $totalBytesAll > 0 ? round(($usedBytesAll / $totalBytesAll) * 100, 1) : 0;

        return [
            'status' => 'success',
            'online_disks' => $onlineCount,
            'total_disks' => count($disks),
            'total_space_formatted' => $this->formatBytes($totalBytesAll),
            'free_space_formatted' => $this->formatBytes($freeBytesAll),
            'used_space_formatted' => $this->formatBytes($usedBytesAll),
            'used_percent' => $usedPercentAll,
            'disks' => $disks,
        ];
    }

    /**
     * Ejecuta el escáner completo de archivos físicos en las rutas locales.
     *
     * @param string|array|null $customPaths Ruta específica o lista de rutas opcionales
     * @return array Estadísticas del escaneo
     */
    public function scan($customPaths = null): array
    {
        $targetPaths = [];
        if (!empty($customPaths)) {
            $targetPaths = is_array($customPaths) ? $customPaths : [$customPaths];
        } else {
            $targetPaths = $this->getConfiguredPaths();
        }

        Log::info('LocalMediaScannerService::scan: Iniciando escaneo local en rutas: ' . implode(', ', $targetPaths));

        $stats = [
            'total_files_scanned' => 0,
            'total_movies' => 0,
            'total_series' => 0,
            'total_episodes' => 0,
            'errors' => 0,
            'paths_scanned' => [],
        ];

        $categories = $this->ensureDefaultCategories();

        foreach ($targetPaths as $path) {
            $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
            if (!file_exists($normalizedPath) || !is_dir($normalizedPath)) {
                Log::notice("LocalMediaScannerService::scan: La ruta {$normalizedPath} no existe o no está montada.");
                continue;
            }

            $stats['paths_scanned'][] = $normalizedPath;
            $this->scanDirectoryRecursively($normalizedPath, $categories, $stats);
        }

        ServerSetting::set('last_local_scan_at', now()->toIso8601String(), 'media');
        ServerSetting::set('last_sync_at', now()->toIso8601String(), 'media');

        Log::info("LocalMediaScannerService::scan: Finalizado con éxito. Películas: {$stats['total_movies']}, Series: {$stats['total_series']}, Episodios: {$stats['total_episodes']}, Total archivos: {$stats['total_files_scanned']}");

        return $stats;
    }

    /**
     * Recorre recursivamente una carpeta respetando las reglas de exclusión.
     */
    protected function scanDirectoryRecursively(string $dir, array $categories, array &$stats): void
    {
        $handle = @opendir($dir);
        if (!$handle) return;

        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') continue;

            $fullPath = $dir . '/' . $item;

            if (is_dir($fullPath)) {
                if ($this->isIgnoredDir($item)) {
                    continue;
                }
                $this->scanDirectoryRecursively($fullPath, $categories, $stats);
            } elseif (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->videoExtensions)) {
                    // Ignorar archivos temporales o de muestra diminutos (< 5MB)
                    $fileSize = @filesize($fullPath);
                    if ($fileSize !== false && $fileSize < (5 * 1024 * 1024)) {
                        continue;
                    }

                    $stats['total_files_scanned']++;
                    try {
                        $this->indexMediaFile($fullPath, $categories, $stats);
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error("Error indexando archivo [{$fullPath}]: " . $e->getMessage());
                    }
                }
            }
        }

        closedir($handle);
    }

    /**
     * Comprueba si una carpeta debe ser ignorada.
     */
    protected function isIgnoredDir(string $dirName): bool
    {
        $lower = strtolower($dirName);
        foreach ($this->ignoredDirectories as $ignored) {
            if ($lower === strtolower($ignored) || str_contains($lower, strtolower($ignored))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clasifica e indexa un archivo de video en la base de datos con metadatos de TMDb.
     */
    protected function indexMediaFile(string $filePath, array $categories, array &$stats): void
    {
        $normalizedPath = str_replace('\\', '/', $filePath);
        $fileName = pathinfo($normalizedPath, PATHINFO_BASENAME);
        $dirPath = dirname($normalizedPath);

        $classification = $this->classifyMedia($normalizedPath);

        if ($classification['type'] === 'movie') {
            $this->indexMovieFile($normalizedPath, $classification, $categories['movies'] ?? null, $stats);
        } else {
            $this->indexSeriesEpisodeFile($normalizedPath, $classification, $categories['series'] ?? null, $stats);
        }
    }

    /**
     * Clasifica un archivo entre película y serie según carpeta padre y patrones de nombre.
     */
    public function classifyMedia(string $filePath): array
    {
        $normalized = str_replace('\\', '/', $filePath);
        $pathParts = explode('/', $normalized);
        $fileName = end($pathParts);
        $dirName = count($pathParts) >= 2 ? $pathParts[count($pathParts) - 2] : '';

        // Detección de temporada y episodio en nombre de archivo (ej. S01E05, 1x05, Capitulo 03)
        $isEpisode = false;
        $seasonNum = 1;
        $episodeNum = 1;

        if (preg_match('/[sS](\d{1,2})[eE](\d{1,3})/i', $fileName, $m)) {
            $isEpisode = true;
            $seasonNum = (int) $m[1];
            $episodeNum = (int) $m[2];
        } elseif (preg_match('/(?:^|[\s._\-\[])(\d{1,2})x(\d{1,3})/i', $fileName, $m)) {
            $isEpisode = true;
            $seasonNum = (int) $m[1];
            $episodeNum = (int) $m[2];
        } elseif (preg_match('/(?:temporada|season)\s*(\d{1,2})/i', $normalized, $sm) &&
                   preg_match('/(?:capitulo|cap|episodio|ep|e)\s*(\d{1,3})/i', $fileName, $em)) {
            $isEpisode = true;
            $seasonNum = (int) $sm[1];
            $episodeNum = (int) $em[1];
        }

        // Inspeccionar carpetas ascendentes para clasificar
        $hasSeriesFolder = false;
        $hasMovieFolder = false;

        foreach ($pathParts as $part) {
            $p = mb_strtolower($part);
            if (preg_match('/^(series|anime|animado\s*umanet)/i', $p) || str_contains($p, 'series') || str_contains($p, 'anime') || str_contains($p, 'animado')) {
                $hasSeriesFolder = true;
            }
            if (preg_match('/^(estrenos|peliculas|peliculas\s*umanet|infantiles)/i', $p) || str_contains($p, 'peliculas') || str_contains($p, 'estrenos') || str_contains($p, 'infantiles')) {
                $hasMovieFolder = true;
            }
        }

        $type = 'movie';
        if ($isEpisode || ($hasSeriesFolder && !$hasMovieFolder)) {
            $type = 'series';
        }

        return [
            'type' => $type,
            'is_episode' => $isEpisode,
            'season_number' => $seasonNum,
            'episode_number' => $episodeNum,
            'dir_name' => $dirName,
        ];
    }

    /**
     * Limpia el nombre del archivo quitando etiquetas de resolución, códecs y año.
     */
    public function cleanFileName(string $rawFileName): array
    {
        $nameWithoutExt = pathinfo($rawFileName, PATHINFO_FILENAME);

        // Extraer año si existe (1920-2099)
        $year = null;
        if (preg_match('/(?:[\(\[\s._\-])(19\d\d|20\d\d)(?:[\)\]\s._\-]|$)/', $nameWithoutExt, $ym)) {
            $year = (int) $ym[1];
        }

        // Reemplazar separadores por espacios
        $clean = str_replace(['.', '_', '-'], ' ', $nameWithoutExt);

        // Quitar indicadores de temporada/episodio (ej. S01E01, 1x01, Capitulo 1)
        $clean = preg_replace('/[sS]\d{1,2}[eE]\d{1,3}/i', ' ', $clean);
        $clean = preg_replace('/\b\d{1,2}x\d{1,3}\b/i', ' ', $clean);
        $clean = preg_replace('/\b(?:capitulo|episodio|temporada|season)\s*\d+\b/i', ' ', $clean);

        // Quitar etiquetas técnicas de calidad y ripeo
        $tags = [
            '1080p', '720p', '480p', '2160p', '4k', 'uhd', 'hdr10\+', 'hdr10', 'hdr', 'dovi', 'dv',
            'bluray', 'blu-ray', 'brrip', 'bdrip', 'web-dl', 'webdl', 'webrip', 'dvdrip', 'hdtv',
            'x264', 'x265', 'h264', 'h265', 'hevc', 'avc', '10bit', '8bit',
            'aac5\.1', 'aac', 'ac3', 'eac3', 'ddp5\.1', 'dts-hd', 'dts', 'mp3',
            'latino', 'spanish', 'castellano', 'espanol', 'español', 'dual', 'multi', 'subtitulado', 'sub',
            'repack', 'proper', 'remastered', 'unrated', 'extended', 'imax', 'proper',
        ];

        foreach ($tags as $tag) {
            $clean = preg_replace('/\b' . $tag . '\b/i', ' ', $clean);
        }

        // Quitar el año extraído para dejar únicamente el título limpio
        if ($year) {
            $clean = preg_replace('/\b' . $year . '\b/', ' ', $clean);
        }

        // Limpiar corchetes, paréntesis y espacios dobles
        $clean = preg_replace('/[\[\]\(\)\{\}\-_]/', ' ', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        return [
            'title' => $clean ?: pathinfo($rawFileName, PATHINFO_FILENAME),
            'year' => $year,
        ];
    }

    /**
     * Indexa una película en la base de datos sin duplicarla ni borrar historial de usuarios.
     */
    protected function indexMovieFile(string $filePath, array $classification, ?Category $category, array &$stats): void
    {
        $fileName = basename($filePath);
        $parsed = $this->cleanFileName($fileName);
        $cleanTitle = $parsed['title'];
        $year = $parsed['year'];

        // Buscar metadatos en TMDb
        $tmdbData = $this->queryTmdb($cleanTitle, $year, 'movie');

        $finalTitle = !empty($tmdbData['title']) ? $tmdbData['title'] : $cleanTitle;
        $finalYear = !empty($tmdbData['release_year']) ? (int) $tmdbData['release_year'] : ($year ?: (int) date('Y'));
        $slug = Str::slug($finalTitle . '-' . $finalYear) ?: ('movie-' . md5($filePath));

        // Buscar registro existente por ruta física, slug o título + año
        $title = Title::where('file_path', $filePath)
            ->orWhere('source_path', $filePath)
            ->orWhere('stream_path', $filePath)
            ->orWhere('slug', $slug)
            ->first();

        $duration = $this->estimateDuration($filePath);
        $posterUrl = !empty($tmdbData['poster_url']) ? $tmdbData['poster_url'] : '/placeholder-poster.png';
        $backdropUrl = !empty($tmdbData['backdrop_url']) ? $tmdbData['backdrop_url'] : '/placeholder-poster.png';
        $overview = !empty($tmdbData['overview']) ? $tmdbData['overview'] : "Película {$finalTitle} ({$finalYear}) disponible en FlixHN.";
        $genre = !empty($tmdbData['genre']) ? $tmdbData['genre'] : 'Películas';
        $rating = !empty($tmdbData['rating']) ? (float) $tmdbData['rating'] : 8.0;

        if ($title) {
            // Actualizar manteniendo el ID existente (vital para no romper historial de visualización)
            $updateData = [
                'file_path' => $filePath,
                'stream_path' => $filePath,
                'source_path' => $filePath,
                'source_type' => 'local',
                'provider' => 'local',
                'type' => 'movie',
            ];

            // Si el poster actual es de Emby antiguo, reemplazar con TMDb
            if (empty($title->poster_url) || str_contains($title->poster_url, '45.4.87.126:6789') || str_contains($title->poster_url, 'placeholder')) {
                $updateData['poster_url'] = $posterUrl;
            }
            if (empty($title->backdrop_url) || str_contains($title->backdrop_url, '45.4.87.126:6789') || str_contains($title->backdrop_url, 'placeholder')) {
                $updateData['backdrop_url'] = $backdropUrl;
            }
            if (!empty($tmdbData['tmdb_id']) && empty($title->tmdb_id)) {
                $updateData['tmdb_id'] = $tmdbData['tmdb_id'];
            }
            if (empty($title->description) && !empty($overview)) {
                $updateData['description'] = $overview;
            }

            $title->update($updateData);
        } else {
            $title = Title::create([
                'name' => $finalTitle,
                'slug' => $slug,
                'type' => 'movie',
                'category_id' => $category?->id,
                'description' => $overview,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_year' => $finalYear,
                'genre' => $genre,
                'community_rating' => $rating,
                'duration_seconds' => $duration,
                'file_path' => $filePath,
                'stream_path' => $filePath,
                'source_path' => $filePath,
                'source_type' => 'local',
                'provider' => 'local',
                'tmdb_id' => $tmdbData['tmdb_id'] ?? null,
            ]);
            $stats['total_movies']++;
        }

        // Sincronizar MediaItem
        MediaItem::updateOrCreate(
            ['title_id' => $title->id],
            [
                'title' => $finalTitle,
                'type' => 'movie',
                'provider' => 'local',
                'file_path' => $filePath,
                'stream_url' => "/api/media/{$title->id}/stream",
                'overview' => $overview,
                'poster_url' => $title->poster_url,
                'backdrop_url' => $title->backdrop_url,
                'release_year' => $finalYear,
                'genre' => $genre,
                'duration_seconds' => $duration,
                'file_size' => @filesize($filePath) ?: 0,
            ]
        );
    }

    /**
     * Indexa un episodio de serie detectando serie, temporada y capítulo sin duplicados.
     */
    protected function indexSeriesEpisodeFile(string $filePath, array $classification, ?Category $category, array &$stats): void
    {
        $normalized = str_replace('\\', '/', $filePath);
        $pathParts = explode('/', $normalized);
        $fileName = end($pathParts);

        // Identificar el nombre de la serie desde la carpeta superior
        $seriesFolder = '';
        for ($i = count($pathParts) - 2; $i >= 0; $i--) {
            $part = $pathParts[$i];
            // Si es carpeta de temporada o disco, seguir subiendo
            if (preg_match('/^(temporada|season|\bdisk\d+\b|series|anime|animado)/i', $part)) {
                continue;
            }
            $seriesFolder = $part;
            break;
        }

        $parsed = $this->cleanFileName($seriesFolder ?: $fileName);
        $seriesCleanTitle = $parsed['title'];
        $seriesYear = $parsed['year'];

        // Metadatos de la serie en TMDb
        $tmdbData = $this->queryTmdb($seriesCleanTitle, $seriesYear, 'series');

        $finalSeriesTitle = !empty($tmdbData['title']) ? $tmdbData['title'] : $seriesCleanTitle;
        $finalYear = !empty($tmdbData['release_year']) ? (int) $tmdbData['release_year'] : ($seriesYear ?: (int) date('Y'));
        $slug = Str::slug($finalSeriesTitle) ?: ('series-' . md5($finalSeriesTitle));

        // 1. Encontrar o crear la Serie (Title type=series)
        $series = Title::where('type', 'series')
            ->where(function ($q) use ($slug, $finalSeriesTitle) {
                $q->where('slug', $slug)
                  ->orWhere('name', $finalSeriesTitle);
            })
            ->first();

        $posterUrl = !empty($tmdbData['poster_url']) ? $tmdbData['poster_url'] : '/placeholder-poster.png';
        $backdropUrl = !empty($tmdbData['backdrop_url']) ? $tmdbData['backdrop_url'] : '/placeholder-poster.png';
        $overview = !empty($tmdbData['overview']) ? $tmdbData['overview'] : "Serie {$finalSeriesTitle} en FlixHN.";

        if (!$series) {
            $series = Title::create([
                'name' => $finalSeriesTitle,
                'slug' => $slug,
                'type' => 'series',
                'category_id' => $category?->id,
                'description' => $overview,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_year' => $finalYear,
                'genre' => !empty($tmdbData['genre']) ? $tmdbData['genre'] : 'Series',
                'community_rating' => !empty($tmdbData['rating']) ? (float) $tmdbData['rating'] : 8.2,
                'source_type' => 'local',
                'provider' => 'local',
                'tmdb_id' => $tmdbData['tmdb_id'] ?? null,
            ]);
            $stats['total_series']++;
        } else {
            // Actualizar si las carátulas previas eran de Emby
            if (empty($series->poster_url) || str_contains($series->poster_url, '45.4.87.126:6789')) {
                $series->update(['poster_url' => $posterUrl]);
            }
            if (empty($series->backdrop_url) || str_contains($series->backdrop_url, '45.4.87.126:6789')) {
                $series->update(['backdrop_url' => $backdropUrl]);
            }
        }

        // 2. Encontrar o crear la Temporada
        $seasonNumber = $classification['season_number'] ?: 1;
        $season = Season::firstOrCreate(
            [
                'title_id' => $series->id,
                'season_number' => $seasonNumber,
            ],
            [
                'title' => "Temporada {$seasonNumber}",
                'overview' => "Temporada {$seasonNumber} de {$series->name}",
            ]
        );

        // 3. Encontrar o crear el Episodio
        $episodeNumber = $classification['episode_number'] ?: 1;
        $epTitle = "Episodio {$episodeNumber}";

        $episode = Episode::where('title_id', $series->id)
            ->where('season_id', $season->id)
            ->where('episode_number', $episodeNumber)
            ->first();

        if (!$episode) {
            $episode = Episode::where('file_path', $filePath)
                ->orWhere('stream_path', $filePath)
                ->first();
        }

        $duration = $this->estimateDuration($filePath);

        if ($episode) {
            $episode->update([
                'title_id' => $series->id,
                'season_id' => $season->id,
                'episode_number' => $episodeNumber,
                'file_path' => $filePath,
                'stream_path' => $filePath,
                'source_type' => 'local',
                'thumbnail_url' => str_contains((string) $episode->thumbnail_url, '45.4.87.126:6789') ? $backdropUrl : ($episode->thumbnail_url ?: $backdropUrl),
            ]);
        } else {
            Episode::create([
                'title_id' => $series->id,
                'season_id' => $season->id,
                'episode_number' => $episodeNumber,
                'title' => $epTitle,
                'description' => "Capítulo {$episodeNumber} de la temporada {$seasonNumber}.",
                'stream_path' => $filePath,
                'file_path' => $filePath,
                'source_type' => 'local',
                'duration_seconds' => $duration,
                'thumbnail_url' => $backdropUrl,
            ]);
            $stats['total_episodes']++;
        }
    }

    /**
     * Consulta a la API de TMDb en español (es-MX / es-ES).
     */
    public function queryTmdb(string $title, ?int $year = null, string $type = 'movie'): array
    {
        $cacheKey = "{$type}:" . mb_strtolower(trim($title)) . ':' . ($year ?: 'any');
        if (isset($this->tmdbCache[$cacheKey])) {
            return $this->tmdbCache[$cacheKey];
        }

        $apiKey = env('TMDB_API_KEY');
        if (empty($apiKey)) {
            $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
            $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        }

        if (empty($apiKey) || str_starts_with($apiKey, 'tmdb_live_key')) {
            // Si no hay API key oficial de TMDb configurada, retornar fallback limpio
            $fallback = [
                'title' => $title,
                'release_year' => $year ?: (int) date('Y'),
                'poster_url' => '/placeholder-poster.png',
                'backdrop_url' => '/placeholder-poster.png',
                'overview' => "{$title} disponible en la biblioteca multimedia local de FlixHN.",
                'genre' => $type === 'movie' ? 'Películas' : 'Series',
                'rating' => 8.0,
                'tmdb_id' => null,
            ];
            $this->tmdbCache[$cacheKey] = $fallback;
            return $fallback;
        }

        try {
            $endpoint = ($type === 'series')
                ? 'https://api.themoviedb.org/3/search/tv'
                : 'https://api.themoviedb.org/3/search/movie';

            $params = [
                'api_key' => $apiKey,
                'query' => $title,
                'language' => 'es-MX',
                'include_adult' => 'false',
            ];

            if ($year) {
                if ($type === 'series') {
                    $params['first_air_date_year'] = $year;
                } else {
                    $params['year'] = $year;
                }
            }

            $response = Http::timeout(6)->get($endpoint, $params);

            // Reintentar en es-ES si es-MX no tiene resultados
            if (!$response->successful() || empty($response->json('results'))) {
                $params['language'] = 'es-ES';
                $response = Http::timeout(6)->get($endpoint, $params);
            }

            // Reintentar sin año si falló con año
            if ((!$response->successful() || empty($response->json('results'))) && $year) {
                unset($params['year'], $params['first_air_date_year']);
                $response = Http::timeout(6)->get($endpoint, $params);
            }

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                if (!empty($results[0])) {
                    $first = $results[0];
                    $resTitle = $first['title'] ?? $first['name'] ?? $title;
                    $dateStr = $first['release_date'] ?? $first['first_air_date'] ?? '';
                    $resYear = !empty($dateStr) ? (int) substr($dateStr, 0, 4) : ($year ?: (int) date('Y'));

                    $poster = !empty($first['poster_path'])
                        ? "https://image.tmdb.org/t/p/w500{$first['poster_path']}"
                        : '/placeholder-poster.png';

                    $backdrop = !empty($first['backdrop_path'])
                        ? "https://image.tmdb.org/t/p/w1280{$first['backdrop_path']}"
                        : '/placeholder-poster.png';

                    $overview = $first['overview'] ?? '';

                    $data = [
                        'title' => $resTitle,
                        'release_year' => $resYear,
                        'poster_url' => $poster,
                        'backdrop_url' => $backdrop,
                        'overview' => $overview,
                        'genre' => $this->resolveTmdbGenre($first['genre_ids'] ?? []),
                        'rating' => (float) ($first['vote_average'] ?? 8.0),
                        'tmdb_id' => (string) ($first['id'] ?? ''),
                    ];

                    $this->tmdbCache[$cacheKey] = $data;
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error consultando TMDb para [{$title}]: " . $e->getMessage());
        }

        $fallback = [
            'title' => $title,
            'release_year' => $year ?: (int) date('Y'),
            'poster_url' => '/placeholder-poster.png',
            'backdrop_url' => '/placeholder-poster.png',
            'overview' => "{$title} disponible en la biblioteca de FlixHN.",
            'genre' => $type === 'movie' ? 'Películas' : 'Series',
            'rating' => 8.0,
            'tmdb_id' => null,
        ];
        $this->tmdbCache[$cacheKey] = $fallback;
        return $fallback;
    }

    /**
     * Resuelve los nombres de los géneros comunes de TMDb.
     */
    protected function resolveTmdbGenre(array $genreIds): string
    {
        $map = [
            28 => 'Acción', 12 => 'Aventura', 16 => 'Animación', 35 => 'Comedia',
            80 => 'Crimen', 99 => 'Documental', 18 => 'Drama', 10751 => 'Familia',
            14 => 'Fantasía', 36 => 'Historia', 27 => 'Terror', 10402 => 'Música',
            9648 => 'Misterio', 10749 => 'Romance', 878 => 'Ciencia Ficción',
            10770 => 'Película de TV', 53 => 'Suspense', 10752 => 'Guerra', 37 => 'Western',
            10759 => 'Acción y Aventura', 10762 => 'Infantil', 10763 => 'Noticias',
            10764 => 'Reality', 10765 => 'Ciencia Ficción y Fantasía', 10766 => 'Telenovela',
            10767 => 'Charla', 10768 => 'Guerra y Política',
        ];

        $genres = [];
        foreach ($genreIds as $id) {
            if (isset($map[$id])) {
                $genres[] = $map[$id];
            }
        }

        return !empty($genres) ? implode(' / ', array_slice($genres, 0, 3)) : 'Acción / Aventura';
    }

    /**
     * Estima la duración en segundos a partir de metadatos o tamaño de archivo.
     */
    protected function estimateDuration(string $filePath): int
    {
        // Duración aproximada según tamaño (para 1080p ~ 2GB -> ~ 90 minutos)
        $size = @filesize($filePath);
        if ($size && $size > 100000000) {
            $mb = $size / (1024 * 1024);
            if ($mb > 3000) return 7200; // 2 horas
            if ($mb > 1500) return 5400; // 90 minutos
            if ($mb > 500) return 3600;  // 60 minutos
            return 2700; // 45 minutos
        }
        return 5400; // 90 minutos por defecto
    }

    /**
     * Asegura las categorías de catálogo base en la base de datos.
     */
    protected function ensureDefaultCategories(): array
    {
        $categories = [];
        $categories['movies'] = Category::firstOrCreate(
            ['slug' => 'peliculas'],
            ['name' => 'Películas', 'icon' => 'Film', 'is_visible' => true]
        );
        $categories['series'] = Category::firstOrCreate(
            ['slug' => 'series'],
            ['name' => 'Series', 'icon' => 'Tv', 'is_visible' => true]
        );
        Category::firstOrCreate(
            ['slug' => 'estrenos'],
            ['name' => 'Estrenos', 'icon' => 'Flame', 'is_visible' => true]
        );
        Category::firstOrCreate(
            ['slug' => 'infantiles'],
            ['name' => 'Infantiles', 'icon' => 'Smile', 'is_visible' => true]
        );
        return $categories;
    }

    /**
     * Formateador de bytes a cadena legible.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
    }
}

