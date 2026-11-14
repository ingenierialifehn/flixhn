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
        'ts',
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
     * @param bool $cleanRemote Limpiar registros huérfanos o remotos de Emby
     * @return array Estadísticas del escaneo
     */
    public function scan($customPaths = null, bool $cleanRemote = true): array
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

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
            'purged_remote' => 0,
            'errors' => 0,
            'paths_scanned' => [],
        ];

        // Purgar siempre registros huérfanos de servidores remotos/Emby para dar prioridad absoluta a medios locales
        $stats['purged_remote'] = $this->cleanRemoteEmbyRecords();

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

        // Limpiar la caché del catálogo principal inmediatamente para que /browse muestre los nuevos contenidos
        \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
        \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
        \Illuminate\Support\Facades\Cache::forget('catalog_home');
        \Illuminate\Support\Facades\Cache::forget('titles_all');
        \Illuminate\Support\Facades\Cache::forget('catalog_all');

        Log::info("LocalMediaScannerService::scan: Finalizado con éxito. Películas: {$stats['total_movies']}, Series: {$stats['total_series']}, Episodios: {$stats['total_episodes']}, Total archivos: {$stats['total_files_scanned']}");

        return $stats;
    }

    /**
     * Elimina registros remotos huérfanos de Emby (45.4.87.126 o remote://) para que no bloqueen el catálogo local.
     */
    public function cleanRemoteEmbyRecords(): int
    {
        $purged = 0;
        try {
            $remoteTitles = Title::where(function ($q) {
                $q->where('source_path', 'LIKE', 'remote://%')
                  ->orWhere('stream_path', 'LIKE', '%45.4.87.126%')
                  ->orWhere('source_path', 'LIKE', '%45.4.87.126%')
                  ->orWhere('poster_url', 'LIKE', '%45.4.87.126%')
                  ->orWhere('provider', 'emby');
            })->get();

            $integrity = app(MediaIntegrityService::class);
            foreach ($remoteTitles as $rt) {
                Log::info("cleanRemoteEmbyRecords: Purgando título remoto huérfano [ID {$rt->id}: {$rt->name}]");
                $integrity->purgeTitle($rt);
                $purged++;
            }
        } catch (\Throwable $e) {
            Log::warning("cleanRemoteEmbyRecords error: " . $e->getMessage());
        }

        // Purgar también ítems de medios huérfanos remotos
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('media_items')) {
                MediaItem::where(function ($q) {
                    $q->where('file_path', 'LIKE', '%45.4.87.126%')
                      ->orWhere('file_path', 'LIKE', 'remote://%')
                      ->orWhere('stream_url', 'LIKE', '%45.4.87.126%')
                      ->orWhere('provider', 'emby');
                })->delete();
            }
        } catch (\Throwable $e) {
            Log::debug("cleanRemoteEmbyRecords: MediaItem delete: " . $e->getMessage());
        }

        return $purged;
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
     * Busca imágenes locales (poster, folder, cover, backdrop, fanart) en la carpeta dada.
     * Soporta todas las variantes de nombres generadas por Emby, Jellyfin, Kodi y archivos locales.
     * Retorna un arreglo asociativo con ['poster' => '/api/media/local-image?path=...', 'backdrop' => ...]
     */
    public function findLocalArtwork(string $dirPath, string $baseFileName = ''): array
    {
        $normalizedDir = rtrim(str_replace('\\', '/', $dirPath), '/');
        $result = [
            'poster' => null,
            'backdrop' => null,
        ];

        if (!is_dir($normalizedDir)) {
            return $result;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $videoExtensions = ['mp4', 'mkv', 'avi', 'm4v', 'mov', 'webm', 'ts'];

        // Indexar archivos de la carpeta en minúsculas para resolución case-insensitive instantánea
        $entries = @scandir($normalizedDir) ?: [];
        $filesLower = [];
        $videoCount = 0;

        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..' && is_file("{$normalizedDir}/{$e}")) {
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                if (in_array($ext, $videoExtensions)) {
                    $videoCount++;
                }

                // REGLA ESTRICTA: Ignorar totalmente index.jpeg, index2.jpeg o cualquier index*.jpeg/jpg
                $baseNameLower = strtolower(pathinfo($e, PATHINFO_FILENAME));
                if (preg_match('/^index\d*$/i', $baseNameLower)) {
                    continue;
                }

                $filesLower[strtolower($e)] = $e;
            }
        }

        $isSharedDir = !empty($baseFileName) && $videoCount > 1;

        // 1. Nombres canónicos prioritarios: si hay archivo de video, PRIORIDAD MÁXIMA a nombres homónimos
        $posterNames = [];
        if (!empty($baseFileName)) {
            $baseClean = pathinfo($baseFileName, PATHINFO_FILENAME);
            $posterNames[] = "{$baseClean}-poster";
            $posterNames[] = "{$baseClean}_poster";
            $posterNames[] = "{$baseClean}-cover";
            $posterNames[] = "{$baseClean}_cover";
            $posterNames[] = $baseClean;
        }

        // Si la carpeta es dedicada (no compartida con decenas de películas), se pueden usar los nombres estándar
        if (!$isSharedDir) {
            $posterNames = array_merge($posterNames, [
                'poster',
                'folder',
                'cover',
                'default',
                'movie',
                'season-all-poster',
            ]);
        }

        // 2. Candidatos prioritarios de backdrop / fanart
        $backdropNames = [];
        if (!empty($baseFileName)) {
            $baseClean = pathinfo($baseFileName, PATHINFO_FILENAME);
            $backdropNames[] = "{$baseClean}-fanart";
            $backdropNames[] = "{$baseClean}-backdrop";
            $backdropNames[] = "{$baseClean}_fanart";
            $backdropNames[] = "{$baseClean}_backdrop";
            $backdropNames[] = "{$baseClean}-thumb";
        }

        if (!$isSharedDir) {
            $backdropNames = array_merge($backdropNames, ['backdrop', 'fanart', 'landscape', 'thumb']);
        }

        // 1. Buscar poster por nombres exactos estándar
        foreach ($posterNames as $name) {
            foreach ($imageExtensions as $ext) {
                $target = strtolower("{$name}.{$ext}");
                if (isset($filesLower[$target])) {
                    $cand = "{$normalizedDir}/" . $filesLower[$target];
                    if (@filesize($cand) > 100) {
                        $result['poster'] = '/api/media/local-image?path=' . urlencode($cand);
                        break 2;
                    }
                }
            }
        }

        // 2. Si no se encontró poster exacto y la carpeta es dedicada, buscar cualquier *-poster.ext
        if (!$result['poster'] && !$isSharedDir) {
            foreach ($filesLower as $fileNameLower => $actualFileName) {
                $ext = strtolower(pathinfo($fileNameLower, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions)) {
                    $fnWithoutExt = pathinfo($fileNameLower, PATHINFO_FILENAME);
                    if (str_ends_with($fnWithoutExt, '-poster') || str_ends_with($fnWithoutExt, '_poster')) {
                        $cand = "{$normalizedDir}/{$actualFileName}";
                        if (@filesize($cand) > 100) {
                            $result['poster'] = '/api/media/local-image?path=' . urlencode($cand);
                            break;
                        }
                    }
                }
            }
        }

        // 3. Buscar backdrop por nombres exactos estándar
        foreach ($backdropNames as $name) {
            foreach ($imageExtensions as $ext) {
                $target = strtolower("{$name}.{$ext}");
                if (isset($filesLower[$target])) {
                    $cand = "{$normalizedDir}/" . $filesLower[$target];
                    if (@filesize($cand) > 100) {
                        $result['backdrop'] = '/api/media/local-image?path=' . urlencode($cand);
                        break 2;
                    }
                }
            }
        }

        // 4. Si no se encontró backdrop exacto y es carpeta dedicada, buscar archivos que contengan *backdrop* o *fanart*
        if (!$result['backdrop'] && !$isSharedDir) {
            foreach ($filesLower as $fileNameLower => $actualFileName) {
                $ext = strtolower(pathinfo($fileNameLower, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions)) {
                    if (str_contains($fileNameLower, 'backdrop') || str_contains($fileNameLower, 'fanart')) {
                        $cand = "{$normalizedDir}/{$actualFileName}";
                        if (@filesize($cand) > 100) {
                            $result['backdrop'] = '/api/media/local-image?path=' . urlencode($cand);
                            break;
                        }
                    }
                }
            }
        }

        // 5. Si es carpeta de serie y no hay carátula directa, explorar subcarpetas inmediatas (Season 1, etc.)
        if (!$result['poster'] && empty($baseFileName)) {
            foreach ($entries as $sub) {
                if ($sub === '.' || $sub === '..') continue;
                $subPath = "{$normalizedDir}/{$sub}";
                if (is_dir($subPath) && !$this->isIgnoredDir($sub)) {
                    $subArt = $this->findLocalArtwork($subPath);
                    if (!empty($subArt['poster'])) {
                        $result['poster'] = $subArt['poster'];
                        if (!$result['backdrop']) {
                            $result['backdrop'] = $subArt['backdrop'] ?: $subArt['poster'];
                        }
                        break;
                    }
                }
            }
        }

        // 6. Si hay poster pero no backdrop, usar el poster como backdrop y viceversa
        if ($result['poster'] && !$result['backdrop']) {
            $result['backdrop'] = $result['poster'];
        } elseif (!$result['poster'] && $result['backdrop']) {
            $result['poster'] = $result['backdrop'];
        }

        return $result;
    }

    /**
     * Busca cualquier imagen válida (.jpg, .jpeg, .png, .webp) dentro de la carpeta y sus subcarpetas inmediatas,
     * ignorando estrictamente index.jpeg, index2.jpeg e index.jpg.
     */
    public function findAnyLocalValidImage(string $dirPath): ?string
    {
        if (empty($dirPath) || !is_dir($dirPath)) {
            return null;
        }

        $normalized = rtrim(str_replace('\\', '/', $dirPath), '/');
        $validExts = ['jpg', 'jpeg', 'png', 'webp'];

        try {
            // 1. Explorar directorio directo
            $entries = @scandir($normalized);
            if ($entries) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    $entryLower = strtolower($entry);
                    if (in_array($entryLower, ['index.jpeg', 'index2.jpeg', 'index.jpg'])) {
                        continue;
                    }
                    $fullPath = "{$normalized}/{$entry}";
                    if (is_file($fullPath)) {
                        $ext = strtolower(pathinfo($entryLower, PATHINFO_EXTENSION));
                        if (in_array($ext, $validExts) && @filesize($fullPath) > 100) {
                            return '/api/media/local-image?path=' . urlencode($fullPath);
                        }
                    }
                }

                // 2. Explorar subdirectorios (ej: Season 1, etc.)
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    $subPath = "{$normalized}/{$entry}";
                    if (is_dir($subPath) && !$this->isIgnoredDir($entry)) {
                        $subEntries = @scandir($subPath);
                        if ($subEntries) {
                            foreach ($subEntries as $subEntry) {
                                if ($subEntry === '.' || $subEntry === '..') continue;
                                $subLower = strtolower($subEntry);
                                if (in_array($subLower, ['index.jpeg', 'index2.jpeg', 'index.jpg'])) {
                                    continue;
                                }
                                $subFullPath = "{$subPath}/{$subEntry}";
                                if (is_file($subFullPath)) {
                                    $subExt = strtolower(pathinfo($subLower, PATHINFO_EXTENSION));
                                    if (in_array($subExt, $validExts) && @filesize($subFullPath) > 100) {
                                        return '/api/media/local-image?path=' . urlencode($subFullPath);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug("findAnyLocalValidImage error en {$dirPath}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Consulta APIs públicas gratuitas (JustWatch GraphQL, TVMaze e iTunes Search API) sin requerir API Key,
     * para obtener carátula oficial en alta resolución y metadatos de series o películas con validación estricta de título.
     */
    public function fetchPublicOnlineArtwork(string $name, bool $isSeries = false): ?array
    {
        $parsed = $this->cleanFileName($name);
        $cleanName = trim($parsed['title'] ?: $name);
        if (empty($cleanName)) {
            return null;
        }

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 FlixHN/2.0',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // 1. JustWatch GraphQL: Catálogo con carátulas oficiales en español de máxima fidelidad
        try {
            $jwQuery = 'query GetSearchTitles($country: Country!, $searchFilter: TitleFilter!) { popularTitles(country: $country, filter: $searchFilter, first: 3) { edges { node { content(country: $country, language: "es") { title originalTitle posterUrl backdrops { backdropUrl } } } } } }';
            $jwPayload = [
                'query' => $jwQuery,
                'variables' => [
                    'country' => 'MX',
                    'searchFilter' => [
                        'searchQuery' => $cleanName,
                    ],
                ],
            ];
            $jwResp = Http::timeout(5)->withHeaders($headers)->post('https://apis.justwatch.com/graphql', $jwPayload);
            if ($jwResp->successful()) {
                $edges = $jwResp->json('data.popularTitles.edges') ?: [];
                foreach ($edges as $edge) {
                    $content = $edge['node']['content'] ?? null;
                    if (!$content || empty($content['posterUrl'])) continue;

                    $candTitle = $content['title'] ?? '';
                    $candOriginal = $content['originalTitle'] ?? '';

                    similar_text(mb_strtolower($cleanName), mb_strtolower($candTitle), $sim1);
                    similar_text(mb_strtolower($cleanName), mb_strtolower($candOriginal), $sim2);
                    $maxSim = max($sim1, $sim2);

                    $containsClean = str_contains(mb_strtolower($candTitle), mb_strtolower($cleanName)) ||
                                     str_contains(mb_strtolower($cleanName), mb_strtolower($candTitle));

                    if ($maxSim >= 55 || $containsClean) {
                        $pUrl = 'https://images.justwatch.com' . str_replace(['{profile}', '{format}'], ['s592', 'jpg'], $content['posterUrl']);
                        $bUrl = null;
                        if (!empty($content['backdrops'][0]['backdropUrl'])) {
                            $bUrl = 'https://images.justwatch.com' . str_replace(['{profile}', '{format}'], ['s1920', 'jpg'], $content['backdrops'][0]['backdropUrl']);
                        }
                        return [
                            'poster_url' => $pUrl,
                            'backdrop_url' => $bUrl ?: $pUrl,
                            'imdb_id' => null,
                            'source' => 'justwatch',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug("fetchPublicOnlineArtwork JustWatch error para {$cleanName}: " . $e->getMessage());
        }

        // 2. TVMaze: Excelente para series de TV y anime con verificación de similitud
        if ($isSeries) {
            try {
                $tvmazeUrl = 'https://api.tvmaze.com/singlesearch/shows?q=' . urlencode($cleanName);
                $resp = Http::timeout(5)->withHeaders($headers)->get($tvmazeUrl);
                if ($resp->successful()) {
                    $json = $resp->json();
                    $poster = $json['image']['original'] ?? $json['image']['medium'] ?? null;
                    $showName = $json['name'] ?? '';
                    similar_text(mb_strtolower($cleanName), mb_strtolower($showName), $sim);
                    if ($poster && ($sim >= 50 || str_contains(mb_strtolower($showName), mb_strtolower($cleanName)))) {
                        return [
                            'poster_url' => $poster,
                            'backdrop_url' => $poster,
                            'imdb_id' => $json['externals']['imdb'] ?? null,
                            'source' => 'tvmaze',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("fetchPublicOnlineArtwork TVMaze error para {$cleanName}: " . $e->getMessage());
            }
        }

        // 3. iTunes Search API con VALIDACIÓN ESTRICTA de coincidencia de título
        $mediaTypes = $isSeries ? ['tvShow', 'movie'] : ['movie', 'tvShow'];
        foreach ($mediaTypes as $media) {
            try {
                $itunesUrl = "https://itunes.apple.com/search?term=" . urlencode($cleanName) . "&media={$media}&limit=3";
                $resp = Http::timeout(5)->withHeaders($headers)->get($itunesUrl);
                if ($resp->successful()) {
                    $results = $resp->json('results') ?: [];
                    foreach ($results as $resItem) {
                        if (empty($resItem['artworkUrl100'])) continue;

                        $track = $resItem['trackName'] ?? ($resItem['collectionName'] ?? '');
                        similar_text(mb_strtolower($cleanName), mb_strtolower($track), $sim);
                        $contains = str_contains(mb_strtolower($track), mb_strtolower($cleanName)) ||
                                    str_contains(mb_strtolower($cleanName), mb_strtolower($track));

                        if ($sim >= 55 || $contains) {
                            $rawArtwork = $resItem['artworkUrl100'];
                            $hqArtwork = str_replace('100x100bb', '600x900bb', $rawArtwork);
                            return [
                                'poster_url' => $hqArtwork,
                                'backdrop_url' => $hqArtwork,
                                'imdb_id' => null,
                                'source' => 'itunes',
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("fetchPublicOnlineArtwork iTunes error para {$cleanName} ({$media}): " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Asignación automática de Carátula y Fondo (poster_url y backdrop_url):
     * 1. Imagen local específica con el mismo nombre del video (NombreArchivo-poster.jpg, NombreArchivo.jpg)
     *    o en carpeta exclusiva (poster.jpg, folder.jpg), ignorando SIEMPRE index.jpeg e index2.jpeg -> /api/media/local-image?path=...
     * 2. Si el .nfo tiene <imdbid> válido (ej. tt2318092), asigna directamente Metahub:
     *    poster_url = "https://images.metahub.space/poster/medium/{$imdbId}/img"
     *    backdrop_url = "https://images.metahub.space/background/medium/{$imdbId}/img"
     * 3. Si además TMDB_API_KEY está configurada en .env y solo hay tmdb_id, consúltalo en TMDb.
     * 4. Fallback si el .nfo incluye URLs públicas seguras de image.tmdb.org.
     * 5. Búsqueda de cualquier imagen válida (.jpg, .png, .webp) en carpeta o subcarpetas.
     * 6. Consulta a APIs públicas gratuitas (TVMaze / iTunes Search API).
     */
    public function resolvePosterAndBackdrop(string $dirPath, string $fileName = '', ?array $nfo = null, bool $isSeries = false, ?string $titleName = null): array
    {
        $posterUrl = null;
        $backdropUrl = null;
        $foundImdbId = null;

        // 1. Imagen local específica o en carpeta exclusiva (PROHIBIDO index.jpeg e index2.jpeg)
        if (!empty($dirPath) && is_dir($dirPath)) {
            $art = $this->findLocalArtwork($dirPath, $fileName);
            if (!empty($art['poster']) && !str_contains($art['poster'], 'index.jpeg') && !str_contains($art['poster'], 'index2.jpeg')) {
                $posterUrl = $art['poster'];
                $backdropUrl = $art['backdrop'] ?: $posterUrl;
            }
        }

        // 2. Metahub directo si hay <imdbid> válido (ej: tt2318092), sin necesidad de API Key
        $imdbId = $nfo['imdb_id'] ?? null;
        if (!$posterUrl && !empty($imdbId) && preg_match('/^tt\d+$/i', $imdbId)) {
            $posterUrl = "https://images.metahub.space/poster/medium/{$imdbId}/img";
            $backdropUrl = "https://images.metahub.space/background/medium/{$imdbId}/img";
        }

        // 3. Si además TMDB_API_KEY está configurada en .env y solo hay tmdb_id, consultarlo en TMDb
        $tmdbId = $nfo['tmdb_id'] ?? null;
        $tmdbApiKey = env('TMDB_API_KEY') ?: config('services.tmdb.api_key');
        if (!$posterUrl && !empty($tmdbApiKey) && !empty($tmdbId)) {
            $type = $isSeries ? 'series' : 'movie';
            $tmdbMeta = $this->queryTmdb($nfo['name'] ?? $nfo['title'] ?? '', $nfo['release_year'] ?? null, $type, $tmdbId);
            if (!empty($tmdbMeta['poster_url']) && $tmdbMeta['poster_url'] !== '/placeholder-poster.png') {
                $posterUrl = $tmdbMeta['poster_url'];
                if (!$backdropUrl || $backdropUrl === '/placeholder-poster.png') {
                    $backdropUrl = $tmdbMeta['backdrop_url'] ?: $posterUrl;
                }
            }
        }

        // 4. Fallback si el .nfo incluye URLs públicas seguras de image.tmdb.org
        if (!$posterUrl && !empty($nfo['poster_url']) && !str_contains($nfo['poster_url'], '45.4.87.126')) {
            $posterUrl = $nfo['poster_url'];
        }
        if (!$backdropUrl && !empty($nfo['backdrop_url']) && !str_contains($nfo['backdrop_url'], '45.4.87.126')) {
            $backdropUrl = $nfo['backdrop_url'];
        }

        // 5. Si no tiene imdb_id ni imagen nombrada estándar, buscar imagen válida en carpeta SOLO si es carpeta dedicada
        if (!$posterUrl && !empty($dirPath) && is_dir($dirPath)) {
            $entries = @scandir($dirPath) ?: [];
            $videoCount = 0;
            $videoExts = ['mp4', 'mkv', 'avi', 'm4v', 'mov', 'webm', 'ts'];
            foreach ($entries as $e) {
                if ($e !== '.' && $e !== '..' && is_file("{$dirPath}/{$e}")) {
                    $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                    if (in_array($ext, $videoExts)) $videoCount++;
                }
            }
            if ($videoCount <= 1) {
                $anyLocal = $this->findAnyLocalValidImage($dirPath);
                if ($anyLocal) {
                    $posterUrl = $anyLocal;
                    $backdropUrl = $anyLocal;
                }
            }
        }

        // 6. Si no hay imagen en carpeta local y no tiene imdb_id, consultar TVMaze o iTunes Search API
        if (!$posterUrl) {
            $searchTitle = $titleName ?: ($nfo['name'] ?? $nfo['title'] ?? ($fileName ? pathinfo($fileName, PATHINFO_FILENAME) : (is_dir($dirPath) ? basename($dirPath) : '')));
            if (!empty($searchTitle)) {
                $onlineArt = $this->fetchPublicOnlineArtwork($searchTitle, $isSeries);
                if ($onlineArt && !empty($onlineArt['poster_url'])) {
                    $posterUrl = $onlineArt['poster_url'];
                    $backdropUrl = $onlineArt['backdrop_url'] ?: $posterUrl;
                    if (!empty($onlineArt['imdb_id'])) {
                        $foundImdbId = $onlineArt['imdb_id'];
                    }
                }
            }
        }

        return [
            'poster_url' => $posterUrl,
            'backdrop_url' => $backdropUrl ?: $posterUrl,
            'imdb_id' => $foundImdbId,
        ];
    }

    /**
     * Sincroniza rápidamente las carátulas (poster_url y backdrop_url) y metadatos reales de .nfo
     * para películas y series locales en /mnt/media_library/Disk2..Disk7.
     * Recorre en chunks de 100 títulos para no saturar memoria.
     */
    public function syncPosters(?\Closure $progressCallback = null, bool $forceAll = false): array
    {
        $stats = [
            'updated_movies' => 0,
            'updated_series' => 0,
            'not_found' => 0,
            'total' => 0,
            'synced_channels' => 0,
        ];

        $query = Title::query();
        if (!$forceAll) {
            $query->where(function ($q) {
                $q->whereNull('poster_url')
                  ->orWhere('poster_url', '')
                  ->orWhere('poster_url', '/placeholder-poster.png')
                  ->orWhere('poster_url', 'LIKE', '%index.jpeg%')
                  ->orWhere('poster_url', 'LIKE', '%index2.jpeg%')
                  ->orWhere('poster_url', 'LIKE', '%45.4.87.126%')
                  ->orWhere('poster_url', 'LIKE', '%NNVG10009445%');
            });
        }

        $stats['total'] = (clone $query)->count();

        $query->chunkById(100, function ($titles) use (&$stats, $progressCallback) {
            foreach ($titles as $title) {
                $isSeries = $title->type === 'series';
                $dirPath = null;
                $fileName = '';
                $nfo = null;

                $sourcePath = $title->source_path ?: $title->file_path;
                if (empty($sourcePath) && !empty($title->stream_path) && !str_starts_with($title->stream_path, '/api/')) {
                    $sourcePath = $title->stream_path;
                }

                if ($isSeries) {
                    if (!empty($title->source_path) && is_dir($title->source_path)) {
                        $dirPath = $title->source_path;
                    } else {
                        $ep = Episode::where('title_id', $title->id)->whereNotNull('file_path')->first();
                        if ($ep && !empty($ep->file_path)) {
                            $epPath = str_replace('\\', '/', $ep->file_path);
                            $parts = explode('/', $epPath);
                            for ($i = count($parts) - 2; $i >= 0; $i--) {
                                $part = $parts[$i];
                                if (preg_match('/^(temporada|season|\bdisk\d+\b|series|anime)/i', $part)) {
                                    continue;
                                }
                                $dirPath = implode('/', array_slice($parts, 0, $i + 1));
                                break;
                            }
                        }
                    }

                    if ($dirPath && is_dir($dirPath)) {
                        $tvNfoPath = "{$dirPath}/tvshow.nfo";
                        if (file_exists($tvNfoPath) && is_readable($tvNfoPath)) {
                            $nfo = $this->parseLocalNfo($dirPath, '', $tvNfoPath);
                        }
                    }
                } else {
                    if (!empty($sourcePath)) {
                        $sourcePath = str_replace('\\', '/', $sourcePath);
                        $dirPath = is_dir($sourcePath) ? $sourcePath : dirname($sourcePath);
                        $fileName = is_file($sourcePath) ? basename($sourcePath) : '';
                        $nfo = $this->parseLocalNfo($dirPath, $fileName, $sourcePath);
                    }
                }

                if (!$dirPath || !is_dir($dirPath)) {
                    $stats['not_found']++;
                }

                // Determinar poster_url y backdrop_url usando la regla de prioridad estricta
                $artAndNfo = $this->resolvePosterAndBackdrop($dirPath ?: '', $fileName, $nfo, $isSeries, $title->name);
                $posterUrl = $artAndNfo['poster_url'];
                $backdropUrl = $artAndNfo['backdrop_url'];
                if (!empty($artAndNfo['imdb_id']) && empty($title->imdb_id)) {
                    if (!is_array($nfo)) $nfo = [];
                    $nfo['imdb_id'] = $artAndNfo['imdb_id'];
                }

                // Si aún no se encontró y no tiene poster válido, consultar directamente APIs públicas online por el nombre del título
                if (!$posterUrl || $posterUrl === '/placeholder-poster.png' || str_contains($posterUrl, 'index.jpeg') || str_contains($posterUrl, 'index2.jpeg')) {
                    $onlineArt = $this->fetchPublicOnlineArtwork($title->name, $isSeries);
                    if ($onlineArt && !empty($onlineArt['poster_url'])) {
                        $posterUrl = $onlineArt['poster_url'];
                        $backdropUrl = $onlineArt['backdrop_url'] ?: $posterUrl;
                        if (!empty($onlineArt['imdb_id']) && empty($title->imdb_id)) {
                            if (!is_array($nfo)) $nfo = [];
                            $nfo['imdb_id'] = $onlineArt['imdb_id'];
                        }
                    }
                }

                // Si el título quedó con index.jpeg, index2.jpeg o la IP muerta 45.4.87.126, purgarlo
                $isCorruptedCurrentPoster = empty($title->poster_url)
                    || str_contains($title->poster_url, 'index.jpeg')
                    || str_contains($title->poster_url, 'index2.jpeg')
                    || str_contains($title->poster_url, '45.4.87.126');

                if ($isCorruptedCurrentPoster && !$posterUrl) {
                    $posterUrl = '/placeholder-poster.png';
                    $backdropUrl = $backdropUrl ?: '/placeholder-poster.png';
                }

                // Preparar actualizaciones de metadatos reales y carátulas
                $updates = [];

                if ($posterUrl && $posterUrl !== $title->poster_url) {
                    $updates['poster_url'] = $posterUrl;
                }
                if ($backdropUrl && $backdropUrl !== $title->backdrop_url) {
                    $updates['backdrop_url'] = $backdropUrl;
                }

                if (!empty($nfo['name']) && $nfo['name'] !== $title->name && mb_strlen($nfo['name']) > 1) {
                    $updates['name'] = $nfo['name'];
                    $updates['sort_title'] = $nfo['name'];
                }
                if (!empty($nfo['description']) && $nfo['description'] !== $title->description && mb_strlen($nfo['description']) > 5) {
                    $updates['description'] = $nfo['description'];
                }
                if (!empty($nfo['release_year']) && $nfo['release_year'] > 1900 && $nfo['release_year'] !== (int) $title->release_year) {
                    $updates['release_year'] = $nfo['release_year'];
                }
                if (!empty($nfo['community_rating']) && $nfo['community_rating'] > 0 && (float)$nfo['community_rating'] !== (float)$title->community_rating) {
                    $updates['community_rating'] = $nfo['community_rating'];
                }
                if (!empty($nfo['parental_rating']) && $nfo['parental_rating'] !== $title->parental_rating) {
                    $updates['parental_rating'] = $nfo['parental_rating'];
                }
                if (!empty($nfo['duration_seconds']) && $nfo['duration_seconds'] > 0 && (int)$nfo['duration_seconds'] !== (int)$title->duration_seconds) {
                    $updates['duration_seconds'] = $nfo['duration_seconds'];
                }
                if (!empty($nfo['genre']) && $nfo['genre'] !== $title->genre) {
                    $updates['genre'] = $nfo['genre'];
                }
                if (!empty($nfo['tmdb_id']) && $nfo['tmdb_id'] !== $title->tmdb_id) {
                    $updates['tmdb_id'] = $nfo['tmdb_id'];
                }
                if (!empty($nfo['imdb_id']) && $nfo['imdb_id'] !== $title->imdb_id) {
                    $updates['imdb_id'] = $nfo['imdb_id'];
                }

                if (!empty($updates)) {
                    $title->update($updates);

                    if (\Illuminate\Support\Facades\Schema::hasTable('media_items')) {
                        $mediaUpdates = [
                            'poster_url' => $title->poster_url,
                            'backdrop_url' => $title->backdrop_url,
                        ];
                        if (!empty($updates['name'])) $mediaUpdates['title'] = $updates['name'];
                        if (!empty($updates['description'])) $mediaUpdates['overview'] = $updates['description'];
                        if (!empty($updates['release_year'])) $mediaUpdates['release_year'] = $updates['release_year'];
                        if (!empty($updates['duration_seconds'])) $mediaUpdates['duration_seconds'] = $updates['duration_seconds'];

                        MediaItem::where('title_id', $title->id)->update($mediaUpdates);
                    }

                    if ($isSeries) {
                        $stats['updated_series']++;
                    } else {
                        $stats['updated_movies']++;
                    }

                    if ($progressCallback) {
                        $progressCallback($title, $title->poster_url, true);
                    }
                } else {
                    if ($progressCallback) {
                        $progressCallback($title, $title->poster_url, false);
                    }
                }
            }
        });

        // CORRECCIÓN 3: Sincronización y verificación de logos de canales de TV en vivo
        try {
            $channelStats = $this->syncTvChannelLogos();
            $stats['synced_channels'] = $channelStats['synced'];
        } catch (\Throwable $e) {
            Log::debug("syncPosters: Error sincronizando logos de TV: " . $e->getMessage());
        }

        // Limpiar cachés del catálogo inmediatamente
        \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
        \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
        \Illuminate\Support\Facades\Cache::forget('catalog_home');
        \Illuminate\Support\Facades\Cache::forget('titles_all');
        \Illuminate\Support\Facades\Cache::forget('catalog_all');

        return $stats;
    }

    /**
     * Sincroniza y verifica los logotipos de los canales de televisión.
     * Si el archivo físico local no existe en storage/app/public/logos/channels,
     * busca en iptv_logos_index.json o genera un SVG vectorial dinámico.
     */
    public function syncTvChannelLogos(?\Closure $callback = null): array
    {
        $tvLogoService = app(\App\Services\TvLogoService::class);
        $tvLogoService->ensureDirectoryExists();

        $channels = \App\Models\TvChannel::where('is_active', true)->get();
        $stats = [
            'total' => $channels->count(),
            'synced' => 0,
            'existing' => 0,
        ];

        foreach ($channels as $channel) {
            $currentLogo = $channel->getRawOriginal('logo_url');
            $needsSync = false;

            if (empty($currentLogo)) {
                $needsSync = true;
            } elseif (str_starts_with($currentLogo, '/storage/')) {
                $relPath = ltrim(preg_replace('#^/storage/#', '', $currentLogo), '/');
                $fullPath = storage_path("app/public/{$relPath}");
                if (!file_exists($fullPath) || @filesize($fullPath) < 50) {
                    $needsSync = true;
                }
            }

            if ($needsSync) {
                try {
                    $newLogoUrl = $tvLogoService->processChannelLogo($channel, null, false);
                    if ($newLogoUrl) {
                        $channel->logo_url = $newLogoUrl;
                        $channel->saveQuietly();
                        $stats['synced']++;
                        if ($callback) {
                            $callback($channel, $newLogoUrl);
                        }
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::debug("syncTvChannelLogos: Error en canal [{$channel->id} - {$channel->name}]: " . $e->getMessage());
                }
            } else {
                $stats['existing']++;
            }
        }

        return $stats;
    }

    /**
     * Busca y parsea un archivo .nfo de metadatos (Emby/Kodi) en la carpeta del video o homónimo.
     */
    public function parseLocalNfo(string $dirPath, string $baseFileName = '', ?string $fullSourcePath = null): ?array
    {
        $normalizedDir = rtrim(str_replace('\\', '/', $dirPath), '/');

        $nfoPath = null;

        // 1. Si se proporciona la ruta completa del archivo de video, buscar archivo .nfo homónimo exacto
        if (!empty($fullSourcePath)) {
            $normalizedSource = str_replace('\\', '/', $fullSourcePath);
            $homonymous = preg_replace('/\.[^.]+$/', '.nfo', $normalizedSource);
            if (file_exists($homonymous) && !is_dir($homonymous) && @filesize($homonymous) > 10) {
                $nfoPath = $homonymous;
            }
        }

        // 2. Si no se encontró y se proporcionó nombre de archivo base
        if (!$nfoPath && !empty($baseFileName) && is_dir($normalizedDir)) {
            $baseClean = pathinfo($baseFileName, PATHINFO_FILENAME);
            $cand = "{$normalizedDir}/{$baseClean}.nfo";
            if (file_exists($cand) && !is_dir($cand) && @filesize($cand) > 10) {
                $nfoPath = $cand;
            }
        }

        // 3. Buscar tvshow.nfo si es carpeta de serie o no se encontró homónimo
        if (!$nfoPath && is_dir($normalizedDir)) {
            $candTv = "{$normalizedDir}/tvshow.nfo";
            if (file_exists($candTv) && !is_dir($candTv) && @filesize($candTv) > 10) {
                $nfoPath = $candTv;
            }
        }

        // 4. Buscar movie.nfo SOLO si la carpeta es dedicada (no compartida con múltiples películas)
        if (!$nfoPath && is_dir($normalizedDir) && empty($baseFileName)) {
            $candMovie = "{$normalizedDir}/movie.nfo";
            if (file_exists($candMovie) && !is_dir($candMovie) && @filesize($candMovie) > 10) {
                $nfoPath = $candMovie;
            }
        }

        if (!$nfoPath || !is_readable($nfoPath)) {
            return null;
        }

        try {
            $content = @file_get_contents($nfoPath);
            if (empty($content)) {
                return null;
            }

            $data = [
                'nfo_path' => $nfoPath,
            ];

            $cleanCdata = function (?string $val): string {
                if (!$val) return '';
                $val = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', $val);
                return trim(html_entity_decode(strip_tags($val), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            };

            // 1. Título / Nombre real (<title> o <originaltitle>)
            if (preg_match('/<title>(.*?)<\/title>/is', $content, $m)) {
                $data['name'] = $cleanCdata($m[1]);
                $data['title'] = $data['name'];
            } elseif (preg_match('/<originaltitle>(.*?)<\/originaltitle>/is', $content, $m)) {
                $data['name'] = $cleanCdata($m[1]);
                $data['title'] = $data['name'];
            }

            // 2. Sinopsis / Descripción real (<plot> limpiando CDATA, o <overview> o <outline>)
            if (preg_match('/<plot>(.*?)<\/plot>/is', $content, $m)) {
                $data['description'] = $cleanCdata($m[1]);
                $data['overview'] = $data['description'];
            } elseif (preg_match('/<overview>(.*?)<\/overview>/is', $content, $m)) {
                $data['description'] = $cleanCdata($m[1]);
                $data['overview'] = $data['description'];
            } elseif (preg_match('/<outline>(.*?)<\/outline>/is', $content, $m)) {
                $data['description'] = $cleanCdata($m[1]);
                $data['overview'] = $data['description'];
            }

            // 3. Año de estreno (<year> o <premiered>)
            if (preg_match('/<year>(\d{4})<\/year>/is', $content, $m)) {
                $data['release_year'] = (int) $m[1];
            } elseif (preg_match('/<premiered>(\d{4})(?:-\d{2}-\d{2})?<\/premiered>/is', $content, $m)) {
                $data['release_year'] = (int) $m[1];
            } elseif (preg_match('/<dateadded>(\d{4})/is', $content, $m)) {
                $data['release_year'] = (int) $m[1];
            }

            // 4. Identificadores TMDb e IMDb (<imdbid>, <uniqueid type="imdb">, <tmdbid>, etc.)
            if (preg_match('/<imdbid>(tt\d+)<\/imdbid>/is', $content, $m)) {
                $data['imdb_id'] = trim($m[1]);
            } elseif (preg_match('/<uniqueid[^>]*type=["\']imdb["\'][^>]*>(tt\d+)<\/uniqueid>/is', $content, $m)) {
                $data['imdb_id'] = trim($m[1]);
            } elseif (preg_match('/<id>(tt\d+)<\/id>/is', $content, $m)) {
                $data['imdb_id'] = trim($m[1]);
            }

            if (preg_match('/<tmdbid>(\d+)<\/tmdbid>/is', $content, $m)) {
                $data['tmdb_id'] = trim($m[1]);
            } elseif (preg_match('/<uniqueid[^>]*type=["\']tmdb["\'][^>]*>(\d+)<\/uniqueid>/is', $content, $m)) {
                $data['tmdb_id'] = trim($m[1]);
            } elseif (preg_match('/id=["\']moviedb:\/\/(\d+)["\']/is', $content, $m)) {
                $data['tmdb_id'] = trim($m[1]);
            }

            // 5. Calificación (<rating>) y Clasificación de edad (<mpaa>)
            if (preg_match('/<rating>([0-9\.]+)<\/rating>/is', $content, $m)) {
                $data['community_rating'] = (float) $m[1];
                $data['rating'] = (float) $m[1];
            }
            if (preg_match('/<mpaa>(.*?)<\/mpaa>/is', $content, $m)) {
                $data['parental_rating'] = $cleanCdata($m[1]);
            }

            // 6. Duración en segundos (<durationinseconds> o <runtime> * 60)
            if (preg_match('/<durationinseconds>(\d+)<\/durationinseconds>/is', $content, $m)) {
                $data['duration_seconds'] = (int) $m[1];
            } elseif (preg_match('/<runtime>(\d+)<\/runtime>/is', $content, $m)) {
                $data['duration_seconds'] = (int) $m[1] * 60;
            }

            // 7. Género (primer <genre>)
            if (preg_match('/<genre>(.*?)<\/genre>/is', $content, $m)) {
                $data['genre'] = $cleanCdata($m[1]);
            }

            // 6. Extracción de Imágenes (<thumb aspect="poster">, <thumb>, <fanart><thumb>)
            // REGLA ESTRICTA: Ignorar absolutamente cualquier URL que contenga la IP muerta 45.4.87.126
            $posterCandidates = [];
            $backdropCandidates = [];

            $isDeadIp = function (?string $url): bool {
                return empty($url) || str_contains($url, '45.4.87.126');
            };

            // a. Posters: <thumb aspect="poster" ...>
            if (preg_match_all('/<thumb[^>]*aspect=["\']poster["\'][^>]*>(.*?)<\/thumb>/is', $content, $matches)) {
                foreach ($matches[1] as $u) {
                    $u = trim(strip_tags($u));
                    if (!$isDeadIp($u) && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))) {
                        $posterCandidates[] = $u;
                    }
                }
            }

            // b. Backdrops: <fanart><thumb>...</thumb></fanart>
            if (preg_match('/<fanart[^>]*>(.*?)<\/fanart>/is', $content, $fanartBlock)) {
                if (preg_match_all('/<thumb[^>]*>(.*?)<\/thumb>/is', $fanartBlock[1], $matches)) {
                    foreach ($matches[1] as $u) {
                        $u = trim(strip_tags($u));
                        if (!$isDeadIp($u) && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))) {
                            $backdropCandidates[] = $u;
                        }
                    }
                }
            }

            // c. Backdrops: <thumb aspect="landscape|banner|fanart">
            if (preg_match_all('/<thumb[^>]*aspect=["\'](?:landscape|banner|fanart)["\'][^>]*>(.*?)<\/thumb>/is', $content, $matches)) {
                foreach ($matches[1] as $u) {
                    $u = trim(strip_tags($u));
                    if (!$isDeadIp($u) && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))) {
                        $backdropCandidates[] = $u;
                    }
                }
            }

            // d. <thumb>...</thumb> genéricos
            if (preg_match_all('/<thumb[^>]*>(.*?)<\/thumb>/is', $content, $matches)) {
                foreach ($matches[1] as $u) {
                    $u = trim(strip_tags($u));
                    if (!$isDeadIp($u) && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))) {
                        $posterCandidates[] = $u;
                    }
                }
            }

            // Priorizar URLs que apunten a image.tmdb.org
            $posterUrl = null;
            foreach ($posterCandidates as $url) {
                if (str_contains($url, 'image.tmdb.org')) {
                    $posterUrl = $url;
                    break;
                }
            }
            if (!$posterUrl && !empty($posterCandidates)) {
                $posterUrl = $posterCandidates[0];
            }

            $backdropUrl = null;
            foreach ($backdropCandidates as $url) {
                if (str_contains($url, 'image.tmdb.org')) {
                    $backdropUrl = $url;
                    break;
                }
            }
            if (!$backdropUrl && !empty($backdropCandidates)) {
                $backdropUrl = $backdropCandidates[0];
            }

            if ($posterUrl) {
                $data['poster_url'] = $posterUrl;
            }
            if ($backdropUrl) {
                $data['backdrop_url'] = $backdropUrl;
            }

            return !empty($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning("Error leyendo archivo .nfo en {$nfoPath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Indexa una película en la base de datos sin duplicarla ni borrar historial de usuarios.
     */
    protected function indexMovieFile(string $filePath, array $classification, ?Category $category, array &$stats): void
    {
        $fileName = basename($filePath);
        $dirPath = dirname($filePath);
        $parsed = $this->cleanFileName($fileName);
        $cleanTitle = $parsed['title'];
        $year = $parsed['year'];

        // 1. Buscar archivo .nfo local homónimo con metadatos de Emby / Kodi
        $localNfo = $this->parseLocalNfo($dirPath, $fileName, $filePath);

        $finalTitle = !empty($localNfo['name']) ? $localNfo['name'] : (!empty($localNfo['title']) ? $localNfo['title'] : $cleanTitle);
        $finalYear = !empty($localNfo['release_year']) ? (int) $localNfo['release_year'] : ($year ?: (int) date('Y'));
        $overview = !empty($localNfo['description']) ? $localNfo['description'] : (!empty($localNfo['overview']) ? $localNfo['overview'] : null);
        $genre = !empty($localNfo['genre']) ? $localNfo['genre'] : 'Películas';
        $rating = !empty($localNfo['community_rating']) ? (float) $localNfo['community_rating'] : (!empty($localNfo['rating']) ? (float) $localNfo['rating'] : 8.0);
        $parentalRating = $localNfo['parental_rating'] ?? null;
        $tmdbId = $localNfo['tmdb_id'] ?? null;
        $imdbId = $localNfo['imdb_id'] ?? null;
        $duration = !empty($localNfo['duration_seconds']) ? (int) $localNfo['duration_seconds'] : $this->estimateDuration($filePath);

        // 2. Determinar carátula y fondo (regla estricta: ignora index.jpeg/index2.jpeg, prioriza local específico, Metahub si hay IMDb, TMDb si hay API Key)
        $artAndNfo = $this->resolvePosterAndBackdrop($dirPath, $fileName, $localNfo, false);
        $posterUrl = $artAndNfo['poster_url'] ?: '/placeholder-poster.png';
        $backdropUrl = $artAndNfo['backdrop_url'] ?: '/placeholder-poster.png';
        $overview = !empty($overview) ? $overview : "Película {$finalTitle} ({$finalYear}) disponible en FlixHN.";
        $slug = Str::slug($finalTitle . '-' . $finalYear) ?: ('movie-' . md5($filePath));

        // Buscar registro existente por ruta física, slug o título + año
        $title = Title::where('source_path', $filePath)
            ->orWhere('stream_path', $filePath)
            ->orWhere('slug', $slug)
            ->first();

        if ($title) {
            // Actualizar manteniendo el ID existente (vital para no romper historial de visualización)
            $updateData = [
                'name' => $finalTitle,
                'sort_title' => $finalTitle,
                'slug' => $slug,
                'type' => 'movie',
                'provider' => 'local',
                'source_path' => $filePath,
                'category_id' => $category?->id ?: $title->category_id,
                'description' => $overview,
                'release_year' => $finalYear,
                'genre' => $genre,
                'duration_seconds' => $duration,
                'community_rating' => $rating,
            ];

            if (!empty($parentalRating)) $updateData['parental_rating'] = $parentalRating;
            if (!empty($tmdbId)) $updateData['tmdb_id'] = $tmdbId;
            if (!empty($imdbId)) $updateData['imdb_id'] = $imdbId;

            // Reemplazar poster si es local nuevo o si el previo apuntaba a Emby / index.jpeg
            if ($posterUrl !== '/placeholder-poster.png' || empty($title->poster_url) || str_contains((string) $title->poster_url, '45.4.87.126') || str_contains((string) $title->poster_url, 'index.jpeg') || str_contains((string) $title->poster_url, 'index2.jpeg')) {
                $updateData['poster_url'] = $posterUrl;
            }
            if ($backdropUrl !== '/placeholder-poster.png' || empty($title->backdrop_url) || str_contains((string) $title->backdrop_url, '45.4.87.126') || str_contains((string) $title->backdrop_url, 'index.jpeg') || str_contains((string) $title->backdrop_url, 'index2.jpeg')) {
                $updateData['backdrop_url'] = $backdropUrl;
            }

            $title->update($updateData);
            $title->update(['stream_path' => '/api/stream/title/' . $title->id]);
        } else {
            $title = Title::create([
                'name' => $finalTitle,
                'sort_title' => $finalTitle,
                'slug' => $slug,
                'type' => 'movie',
                'provider' => 'local',
                'category_id' => $category?->id,
                'description' => $overview,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_year' => $finalYear,
                'genre' => $genre,
                'community_rating' => $rating,
                'parental_rating' => $parentalRating,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'source_path' => $filePath,
                'stream_path' => '',
                'duration_seconds' => $duration,
            ]);
            $title->update(['stream_path' => '/api/stream/title/' . $title->id]);
            $stats['total_movies']++;
        }

        // Sincronizar MediaItem si existe la tabla
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('media_items')) {
                MediaItem::updateOrCreate(
                    ['title_id' => $title->id],
                    [
                        'title' => $finalTitle,
                        'type' => 'movie',
                        'provider' => 'local',
                        'file_path' => $filePath,
                        'stream_url' => "/api/stream/title/{$title->id}",
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
        } catch (\Throwable) {}
    }

    /**
     * Indexa un episodio de serie detectando serie, temporada y capítulo sin duplicados.
     */
    protected function indexSeriesEpisodeFile(string $filePath, array $classification, ?Category $category, array &$stats): void
    {
        $normalized = str_replace('\\', '/', $filePath);
        $pathParts = explode('/', $normalized);
        $fileName = end($pathParts);
        $epDir = dirname($normalized);

        // Identificar el nombre de la serie desde la carpeta superior
        $seriesFolder = '';
        $seriesDir = $epDir;
        for ($i = count($pathParts) - 2; $i >= 0; $i--) {
            $part = $pathParts[$i];
            if (preg_match('/^(temporada|season|\bdisk\d+\b|series|anime|animado)/i', $part)) {
                continue;
            }
            $seriesFolder = $part;
            $seriesDir = implode('/', array_slice($pathParts, 0, $i + 1));
            break;
        }

        $parsed = $this->cleanFileName($seriesFolder ?: $fileName);
        $seriesCleanTitle = $parsed['title'];
        $seriesYear = $parsed['year'];

        // 1. Carátulas y metadatos locales de la serie (ignora index.jpeg/index2.jpeg, prioriza local, Metahub si hay IMDb, TMDb)
        $seriesNfo = $this->parseLocalNfo($seriesDir);

        $finalSeriesTitle = !empty($seriesNfo['name']) ? $seriesNfo['name'] : (!empty($seriesNfo['title']) ? $seriesNfo['title'] : $seriesCleanTitle);
        $finalYear = !empty($seriesNfo['release_year']) ? (int) $seriesNfo['release_year'] : ($seriesYear ?: (int) date('Y'));
        $overview = !empty($seriesNfo['description']) ? $seriesNfo['description'] : (!empty($seriesNfo['overview']) ? $seriesNfo['overview'] : "Serie {$finalSeriesTitle} en FlixHN.");
        $seriesGenre = $seriesNfo['genre'] ?? 'Series';
        $seriesRating = !empty($seriesNfo['community_rating']) ? (float) $seriesNfo['community_rating'] : (!empty($seriesNfo['rating']) ? (float) $seriesNfo['rating'] : 8.2);
        $seriesParentalRating = $seriesNfo['parental_rating'] ?? null;
        $tmdbId = $seriesNfo['tmdb_id'] ?? null;
        $imdbId = $seriesNfo['imdb_id'] ?? null;

        $seriesArtAndNfo = $this->resolvePosterAndBackdrop($seriesDir, '', $seriesNfo, true);
        $seriesPoster = $seriesArtAndNfo['poster_url'] ?: '/placeholder-poster.png';
        $seriesBackdrop = $seriesArtAndNfo['backdrop_url'] ?: '/placeholder-poster.png';
        $slug = Str::slug($finalSeriesTitle) ?: ('series-' . md5($finalSeriesTitle));

        // 1. Encontrar o crear la Serie (Title type=series)
        $series = Title::where('type', 'series')
            ->where(function ($q) use ($slug, $finalSeriesTitle) {
                $q->where('slug', $slug)
                  ->orWhere('name', $finalSeriesTitle);
            })
            ->first();

        if (!$series) {
            $series = Title::create([
                'name' => $finalSeriesTitle,
                'sort_title' => $finalSeriesTitle,
                'slug' => $slug,
                'type' => 'series',
                'provider' => 'local',
                'category_id' => $category?->id,
                'description' => $overview,
                'poster_url' => $seriesPoster,
                'backdrop_url' => $seriesBackdrop,
                'release_year' => $finalYear,
                'genre' => $seriesGenre,
                'community_rating' => $seriesRating,
                'parental_rating' => $seriesParentalRating,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'source_path' => $seriesDir,
                'stream_path' => '',
                'duration_seconds' => 0,
            ]);
            $series->update(['stream_path' => '/api/stream/title/' . $series->id]);
            $stats['total_series']++;
        } else {
            $updateData = [
                'provider' => 'local',
                'source_path' => $seriesDir,
                'stream_path' => '/api/stream/title/' . $series->id,
                'community_rating' => $seriesRating,
            ];
            if (!empty($seriesParentalRating)) $updateData['parental_rating'] = $seriesParentalRating;
            if (!empty($tmdbId)) $updateData['tmdb_id'] = $tmdbId;
            if (!empty($imdbId)) $updateData['imdb_id'] = $imdbId;

            if ($seriesPoster !== '/placeholder-poster.png' || empty($series->poster_url) || str_contains((string) $series->poster_url, '45.4.87.126') || str_contains((string) $series->poster_url, 'index.jpeg') || str_contains((string) $series->poster_url, 'index2.jpeg')) {
                $updateData['poster_url'] = $seriesPoster;
            }
            if ($seriesBackdrop !== '/placeholder-poster.png' || empty($series->backdrop_url) || str_contains((string) $series->backdrop_url, '45.4.87.126') || str_contains((string) $series->backdrop_url, 'index.jpeg') || str_contains((string) $series->backdrop_url, 'index2.jpeg')) {
                $updateData['backdrop_url'] = $seriesBackdrop;
            }
            $series->update($updateData);
        }

        // Sincronizar MediaItem para la Serie
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('media_items')) {
                MediaItem::updateOrCreate(
                    ['title_id' => $series->id],
                    [
                        'title' => $finalSeriesTitle,
                        'type' => 'series',
                        'provider' => 'local',
                        'file_path' => $seriesDir,
                        'stream_url' => "/api/stream/title/{$series->id}",
                        'overview' => $overview,
                        'poster_url' => $series->poster_url,
                        'backdrop_url' => $series->backdrop_url,
                        'release_year' => $finalYear,
                        'genre' => $seriesGenre,
                        'duration_seconds' => 0,
                        'file_size' => @filesize($filePath) ?: 0,
                    ]
                );
            }
        } catch (\Throwable) {}

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
        $epArt = $this->findLocalArtwork($epDir, $fileName);
        $epNfo = $this->parseLocalNfo($epDir, $fileName, $filePath);

        $epTitle = !empty($epNfo['name']) ? $epNfo['name'] : ($epNfo['title'] ?? "Episodio {$episodeNumber}");
        $epOverview = !empty($epNfo['description']) ? $epNfo['description'] : ($epNfo['overview'] ?? "Capítulo {$episodeNumber} de la temporada {$seasonNumber}.");
        $epThumbnail = (!empty($epArt['backdrop']) && !str_contains($epArt['backdrop'], 'index.jpeg') && !str_contains($epArt['backdrop'], 'index2.jpeg'))
            ? $epArt['backdrop']
            : ((!empty($epArt['poster']) && !str_contains($epArt['poster'], 'index.jpeg') && !str_contains($epArt['poster'], 'index2.jpeg'))
                ? $epArt['poster']
                : $series->backdrop_url);

        $episode = Episode::where('title_id', $series->id)
            ->where('season_id', $season->id)
            ->where('episode_number', $episodeNumber)
            ->first();

        if (!$episode) {
            $episode = Episode::where('file_path', $filePath)->first();
        }

        $duration = $this->estimateDuration($filePath);

        if ($episode) {
            $episode->update([
                'title_id' => $series->id,
                'season_id' => $season->id,
                'episode_number' => $episodeNumber,
                'title' => $epTitle,
                'description' => $epOverview,
                'file_path' => $filePath,
                'stream_path' => '/api/stream/episode/' . $episode->id,
                'source_type' => 'local',
                'thumbnail_url' => str_contains((string) $episode->thumbnail_url, '45.4.87.126') ? $epThumbnail : ($episode->thumbnail_url ?: $epThumbnail),
                'duration_seconds' => $duration,
            ]);
        } else {
            $episode = Episode::create([
                'title_id' => $series->id,
                'season_id' => $season->id,
                'episode_number' => $episodeNumber,
                'title' => $epTitle,
                'description' => $epOverview,
                'file_path' => $filePath,
                'stream_path' => '',
                'source_type' => 'local',
                'duration_seconds' => $duration,
                'thumbnail_url' => $epThumbnail,
            ]);
            $episode->update(['stream_path' => '/api/stream/episode/' . $episode->id]);
            $stats['total_episodes']++;
        }
    }

    /**
     * Consulta a la API de TMDb en español (es-MX / es-ES), soportando búsqueda por ID directo o título/año.
     */
    public function queryTmdb(string $title, ?int $year = null, string $type = 'movie', ?string $tmdbId = null): array
    {
        $cacheKey = !empty($tmdbId)
            ? "{$type}:tmdb:{$tmdbId}"
            : "{$type}:" . mb_strtolower(trim($title)) . ':' . ($year ?: 'any');

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
                'tmdb_id' => $tmdbId,
            ];
            $this->tmdbCache[$cacheKey] = $fallback;
            return $fallback;
        }

        // 1. Si tenemos tmdb_id directo, consultar el endpoint directo por ID
        if (!empty($tmdbId)) {
            try {
                $endpoint = ($type === 'series')
                    ? "https://api.themoviedb.org/3/tv/{$tmdbId}"
                    : "https://api.themoviedb.org/3/movie/{$tmdbId}";

                $response = Http::timeout(6)->get($endpoint, [
                    'api_key' => $apiKey,
                    'language' => 'es-MX',
                ]);

                if (!$response->successful()) {
                    $response = Http::timeout(6)->get($endpoint, [
                        'api_key' => $apiKey,
                        'language' => 'es-ES',
                    ]);
                }

                if ($response->successful()) {
                    $item = $response->json();
                    $resTitle = $item['title'] ?? $item['name'] ?? $title;
                    $dateStr = $item['release_date'] ?? $item['first_air_date'] ?? '';
                    $resYear = !empty($dateStr) ? (int) substr($dateStr, 0, 4) : ($year ?: (int) date('Y'));

                    $poster = !empty($item['poster_path'])
                        ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}"
                        : '/placeholder-poster.png';

                    $backdrop = !empty($item['backdrop_path'])
                        ? "https://image.tmdb.org/t/p/w1280{$item['backdrop_path']}"
                        : '/placeholder-poster.png';

                    $overview = $item['overview'] ?? '';

                    $data = [
                        'title' => $resTitle,
                        'release_year' => $resYear,
                        'poster_url' => $poster,
                        'backdrop_url' => $backdrop,
                        'overview' => $overview,
                        'genre' => $item['genres'][0]['name'] ?? ($type === 'movie' ? 'Películas' : 'Series'),
                        'rating' => (float) ($item['vote_average'] ?? 8.0),
                        'tmdb_id' => (string) $tmdbId,
                    ];

                    $this->tmdbCache[$cacheKey] = $data;
                    return $data;
                }
            } catch (\Throwable $e) {
                Log::warning("Error consultando TMDb por ID [{$tmdbId}]: " . $e->getMessage());
            }
        }

        // 2. Búsqueda por texto (query + año)

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

