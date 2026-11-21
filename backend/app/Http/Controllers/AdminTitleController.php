<?php

namespace App\Http\Controllers;

use App\Models\Title;
use App\Models\User;
use App\Models\Season;
use App\Models\Episode;
use App\Models\Category;
use App\Models\PlaybackProgress;
use App\Models\MediaItem;
use App\Models\DownloadJob;
use App\Models\MediaIngestTask;
use App\Models\OttDownloadTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminTitleController extends Controller
{
    /**
     * Métricas reales y dinámicas del Dashboard de Administración (Telemetría ISP).
     */
    public function dashboardStats(): JsonResponse
    {
        $counts = Cache::remember('admin_dashboard_counts', 30, function () {
            return [
                'titles_count' => Title::count(),
                'total_movies' => Title::where('type', 'movie')->count(),
                'total_series' => Title::where('type', 'series')->count(),
                'subscribers_count' => User::where('role', 'subscriber')->count(),
                'active_subscribers' => User::where('role', 'subscriber')->where('is_active', true)->count(),
            ];
        });

        $titlesCount = $counts['titles_count'];
        $totalMovies = $counts['total_movies'];
        $totalSeries = $counts['total_series'];
        $subscribersCount = $counts['subscribers_count'];
        $activeSubscribers = $counts['active_subscribers'];

        // 1. Telemetría Real del Servidor
        $hostname = gethostname() ?: 'FlixHN-Node';
        $phpVersion = PHP_VERSION;
        $laravelVersion = app()->version();

        // Uptime dinámico desde /proc/uptime (si está disponible en el entorno)
        $uptime = null;
        if (@file_exists('/proc/uptime') && is_readable('/proc/uptime')) {
            $uptimeContent = @file_get_contents('/proc/uptime');
            if ($uptimeContent !== false) {
                $uptimeSec = (int) explode(' ', trim($uptimeContent))[0];
                $days = floor($uptimeSec / 86400);
                $hours = floor(($uptimeSec % 86400) / 3600);
                $minutes = floor(($uptimeSec % 3600) / 60);

                $parts = [];
                if ($days > 0) $parts[] = $days . ' ' . ($days == 1 ? 'día' : 'días');
                if ($hours > 0) $parts[] = $hours . ' hr' . ($hours == 1 ? '' : 's');
                $parts[] = $minutes . ' min' . ($minutes == 1 ? '' : 's');
                $uptime = implode(', ', $parts);
            }
        }

        // Memoria RAM real desde /proc/meminfo
        $memoryUsage = null;
        if (@file_exists('/proc/meminfo') && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false) {
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matchesTotal);
                preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $matchesAvail);
                if (!empty($matchesTotal[1]) && !empty($matchesAvail[1])) {
                    $totalKb = (int) $matchesTotal[1];
                    $availKb = (int) $matchesAvail[1];
                    $usedKb = max(0, $totalKb - $availKb);
                    $usedGb = round($usedKb / 1048576, 1);
                    $totalGb = round($totalKb / 1048576, 1);
                    $memoryUsage = "{$usedGb} GB / {$totalGb} GB";
                }
            }
        }

        // Carga CPU real
        $cpuUsage = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (isset($load[0])) {
                $cpuUsage = round($load[0], 2) . ' (load avg)';
            }
        }

        // Almacenamiento real
        $storagePath = @is_dir('/var/media') ? '/var/media' : '/';
        $storageTotal = null;
        $storageUsed = null;
        $storagePercent = null;
        $totalBytes = @disk_total_space($storagePath);
        $freeBytes = @disk_free_space($storagePath);
        if ($totalBytes && $freeBytes !== false) {
            $usedBytes = max(0, $totalBytes - $freeBytes);
            $storagePercent = round(($usedBytes / $totalBytes) * 100);
            $formatBytes = function ($bytes) {
                if ($bytes >= 1099511627776) {
                    return round($bytes / 1099511627776, 1) . ' TB';
                }
                return round($bytes / 1073741824, 1) . ' GB';
            };
            $storageTotal = $formatBytes($totalBytes);
            $storageUsed = $formatBytes($usedBytes);
        }

        // IP de red LAN
        $lanIp = $_SERVER['SERVER_ADDR'] ?? null;
        if (!$lanIp && @file_exists('/etc/hosts')) {
            $hosts = @file_get_contents('/etc/hosts');
            if ($hosts && preg_match('/^([0-9\.]+)\s+' . preg_quote($hostname, '/') . '/m', $hosts, $m)) {
                $lanIp = $m[1];
            }
        }
        if (!$lanIp) {
            $resolved = gethostbyname($hostname);
            if ($resolved !== $hostname) {
                $lanIp = $resolved;
            }
        }

        // 2. Sesiones en reproducción activa (Now Playing) en los últimos 15 minutos
        $activeSessions = PlaybackProgress::with(['profile.user', 'title', 'episode'])
            ->where('last_watched_at', '>=', now()->subMinutes(15))
            ->orderBy('last_watched_at', 'desc')
            ->get();

        $activeStreams = [];
        foreach ($activeSessions as $session) {
            $user = $session->profile?->user;
            $duration = max(1, $session->duration_seconds ?: 1);
            $current = min($duration, $session->current_seconds ?: 0);
            $percent = min(100, round(($current / $duration) * 100));

            $activeStreams[] = [
                'id' => $session->id,
                'title_name' => $session->title?->name ?? 'Transmisión Local',
                'episode_title' => $session->episode?->title,
                'poster_url' => $session->title?->poster_url ?: $session->title?->backdrop_url,
                'progress_percent' => $percent,
                'current_seconds' => $current,
                'duration_seconds' => $duration,
                'client_device' => $session->device_name ?: 'Navegador Web / SmartTV',
                'client_ip' => $user?->assigned_ip ?: 'Local On-Net',
                'stream_type' => 'Direct Play (HLS)',
                'bitrate' => 'Adaptativo HLS',
                'video_codec' => 'H.264 / AAC',
                'audio_codec' => 'AAC Estéreo',
                'subscriber_name' => $user?->customer_name ?: ($session->profile?->name ?: 'Abonado'),
                'username' => $user?->username ?: 'abonado',
                'state' => 'playing',
            ];
        }

        // 3. Alertas Reales: Obtenidas desde AlertController
        $alertsResponse = app(\App\Http\Controllers\AlertController::class)->index();
        $alerts = $alertsResponse->getData(true)['alerts'] ?? [];

        // 4. Actividad Real: Historial real de reproducciones en PlaybackProgress
        $recentStreams = PlaybackProgress::with(['profile.user', 'title', 'episode'])
            ->orderBy('last_watched_at', 'desc')
            ->take(10)
            ->get();

        $activity = [];
        foreach ($recentStreams as $stream) {
            $user = $stream->profile?->user;
            $userName = $user?->customer_name ?: ($stream->profile?->name ?: 'Abonado');
            $titleName = $stream->title?->name ?: 'Contenido';
            $activity[] = [
                'id' => 'act-' . $stream->id,
                'user' => $userName,
                'avatar_color' => $stream->profile?->avatar_color ?: '#E50914',
                'action' => 'ha reproducido',
                'title' => $titleName,
                'device' => $stream->device_name ?: null,
                'timestamp' => $stream->last_watched_at ? $stream->last_watched_at->toIso8601String() : $stream->updated_at->toIso8601String(),
                'time_ago' => $stream->last_watched_at ? $stream->last_watched_at->diffForHumans() : 'reciente',
            ];
        }

        $mediaServerName = \App\Models\ServerSetting::get('media_server_name')
            ?: \App\Models\ServerSetting::get('emby_server_name', 'server01-PowerEdge-R720');
        $lanDisplay = \App\Models\ServerSetting::get('local_ip_address') ?: '65.187.110.22:6789';
        $wanDisplay = '45.4.87.126:6789';

        $serviceStatus = \App\Models\ServerSetting::get('streaming_service_status', 'online');
        $onNetStatus = match ($serviceStatus) {
            'stopped' => 'Detenido',
            'restarting' => 'Reiniciando',
            default => 'En línea',
        };

        return response()->json([
            'server' => [
                'hostname' => $mediaServerName,
                'php_version' => $phpVersion,
                'laravel_version' => $laravelVersion,
                'version' => 'FlixHN Media Server v2.4 • Core On-Net',
                'uptime' => $uptime,
                'cpu_usage' => $cpuUsage,
                'memory_usage' => $memoryUsage,
                'ram_usage' => $memoryUsage,
                'storage_used' => $storageUsed,
                'storage_total' => $storageTotal,
                'storage_percent' => $storagePercent,
                'lan_ip' => $lanDisplay,
                'wan_ip' => $wanDisplay,
                'is_on_net' => ($serviceStatus !== 'stopped'),
                'on_net_status' => $onNetStatus,
                'service_status' => $serviceStatus,
            ],
            'subscribers_count' => $subscribersCount,
            'titles_count' => $titlesCount,
            'active_streams' => $activeStreams,
            'now_playing' => $activeStreams,
            'alerts' => $alerts,
            'activity' => $activity,
            'metrics' => [
                'total_titles' => $titlesCount,
                'total_movies' => $totalMovies,
                'total_series' => $totalSeries,
                'total_subscribers' => $subscribersCount,
                'active_subscribers' => $activeSubscribers,
                'active_streams_count' => count($activeStreams),
            ],
        ]);
    }

    /**
     * Alias para compatibilidad con llamadas previas a stats()
     */
    public function stats(): JsonResponse
    {
        return $this->dashboardStats();
    }

    /**
     * Detener sesión activa de reproducción desde el panel de administración.
     */
    public function stopSession(int $id): JsonResponse
    {
        $session = PlaybackProgress::find($id);
        if ($session) {
            $session->delete();
        }

        return response()->json([
            'message' => 'Sesión detenida correctamente por el administrador.',
        ]);
    }

    /**
     * Sincronizar catálogo automáticamente desde el servidor de medios local o remoto.
     * POST /api/admin/media/sync
     */
    public function syncRemoteMedia(Request $request, \App\Services\RemoteMediaConnectorService $remoteService): JsonResponse
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        $sourceMode = $request->input('source_mode') ?: \App\Models\ServerSetting::get('media_source_mode', 'local');

        if ($sourceMode === 'local') {
            $scanner = app(\App\Services\LocalMediaScannerService::class);
            $stats = $scanner->scan($request->input('path'), true);
            $totalIndexed = ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0) + ($stats['total_episodes'] ?? 0);

            \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
            \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
            \Illuminate\Support\Facades\Cache::forget('catalog_home');
            \Illuminate\Support\Facades\Cache::forget('titles_all');
            \Illuminate\Support\Facades\Cache::forget('catalog_all');

            return response()->json([
                'success' => true,
                'status' => 'success',
                'source' => 'local_storage',
                'message' => "Catálogo local sincronizado: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios indexados.",
                'total_synced' => $totalIndexed,
                'metrics' => [
                    'total_titles' => ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0),
                    'total_movies' => $stats['total_movies'] ?? 0,
                    'total_series' => $stats['total_series'] ?? 0,
                    'total_episodes' => $stats['total_episodes'] ?? 0,
                    'total_files_scanned' => $stats['total_files_scanned'] ?? 0,
                ],
                'stats' => $stats,
            ]);
        }

        $serverUrl = $request->input('server_url');
        $apiKey = $request->input('api_key');

        return $remoteService->syncCatalog($serverUrl, $apiKey);
    }

    /**
     * Sincronizar catálogo con almacenamiento local y servidor de medios.
     * POST /api/admin/sync-media
     */
    public function syncMedia(Request $request, \App\Services\RemoteMediaConnectorService $remoteService, \App\Services\MediaSyncService $syncService): JsonResponse
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        $sourceMode = $request->input('source_mode') ?: \App\Models\ServerSetting::get('media_source_mode', 'local');

        if ($sourceMode === 'local') {
            $scanner = app(\App\Services\LocalMediaScannerService::class);
            $stats = $scanner->scan($request->input('path'), true);
            $totalIndexed = ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0) + ($stats['total_episodes'] ?? 0);

            \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
            \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
            \Illuminate\Support\Facades\Cache::forget('catalog_home');
            \Illuminate\Support\Facades\Cache::forget('titles_all');
            \Illuminate\Support\Facades\Cache::forget('catalog_all');

            return response()->json([
                'success' => true,
                'status' => 'success',
                'source' => 'local_storage',
                'message' => "Catálogo local sincronizado: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios indexados.",
                'total_synced' => $totalIndexed,
                'metrics' => [
                    'total_titles' => ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0),
                    'total_movies' => $stats['total_movies'] ?? 0,
                    'total_series' => $stats['total_series'] ?? 0,
                    'total_episodes' => $stats['total_episodes'] ?? 0,
                    'total_files_scanned' => $stats['total_files_scanned'] ?? 0,
                ],
                'stats' => $stats,
            ]);
        }

        $serverUrl = $request->input('server_url');
        $apiKey = $request->input('api_key');

        // Ejecutar extracción con el conector remoto
        $response = $remoteService->syncCatalog($serverUrl, $apiKey);

        if ($response->getStatusCode() === 200) {
            return $response;
        }

        // Fallback a escaneo local
        $scanner = app(\App\Services\LocalMediaScannerService::class);
        $stats = $scanner->scan(null, true);
        $totalIndexed = ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0) + ($stats['total_episodes'] ?? 0);

        \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
        \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
        \Illuminate\Support\Facades\Cache::forget('catalog_home');
        \Illuminate\Support\Facades\Cache::forget('titles_all');
        \Illuminate\Support\Facades\Cache::forget('catalog_all');

        return response()->json([
            'success' => true,
            'status' => 'success',
            'source' => 'local_storage',
            'message' => "Catálogo local sincronizado en fallback: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios indexados.",
            'total_synced' => $totalIndexed,
            'metrics' => [
                'total_titles' => ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0),
                'total_movies' => $stats['total_movies'] ?? 0,
                'total_series' => $stats['total_series'] ?? 0,
                'total_episodes' => $stats['total_episodes'] ?? 0,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Endpoint directo para escanear discos locales físicos (/mnt/media_library/Disk2-7).
     * POST /api/admin/media/scan-local
     */
    public function scanLocalMedia(Request $request, \App\Services\LocalMediaScannerService $scanner): JsonResponse
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        try {
            $cleanRemote = $request->boolean('clean_remote') || $request->input('clean_remote') === 'true' || $request->input('clean_remote') === 1;
            $stats = $scanner->scan($request->input('path'), true);
            $totalIndexed = ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0) + ($stats['total_episodes'] ?? 0);

            \Illuminate\Support\Facades\Cache::forget('catalog_home_base');
            \Illuminate\Support\Facades\Cache::forget('catalog_home_kids');
            \Illuminate\Support\Facades\Cache::forget('catalog_home');
            \Illuminate\Support\Facades\Cache::forget('titles_all');
            \Illuminate\Support\Facades\Cache::forget('catalog_all');

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => "Escaneo local finalizado: {$stats['total_movies']} películas, {$stats['total_series']} series y {$stats['total_episodes']} episodios indexados.",
                'total_synced' => $totalIndexed,
                'metrics' => [
                    'total_titles' => ($stats['total_movies'] ?? 0) + ($stats['total_series'] ?? 0),
                    'total_movies' => $stats['total_movies'] ?? 0,
                    'total_series' => $stats['total_series'] ?? 0,
                    'total_episodes' => $stats['total_episodes'] ?? 0,
                    'purged_remote' => $stats['purged_remote'] ?? 0,
                    'total_files_scanned' => $stats['total_files_scanned'] ?? 0,
                ],
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminTitleController::scanLocalMedia Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Error durante el escaneo local: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar carátulas locales de películas y series en segundo plano o bajo demanda.
     * POST /api/admin/media/sync-posters
     */
    public function syncPosters(Request $request, \App\Services\LocalMediaScannerService $scanner): JsonResponse
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        try {
            $stats = $scanner->syncPosters();

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => "Sincronización de carátulas finalizada: {$stats['updated_movies']} películas y {$stats['updated_series']} series actualizadas.",
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AdminTitleController::syncPosters Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Error durante la sincronización de carátulas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar con el servidor de medios remoto.
     * POST /api/admin/sync-emby (mantenido por retrocompatibilidad)
     */
    public function syncEmby(Request $request, \App\Services\RemoteMediaConnectorService $remoteService): JsonResponse
    {
        return $this->syncRemoteMedia($request, $remoteService);
    }

    /**
     * Listar títulos del catálogo con soporte para navegación por directorios / carpetas.
     * Soporta parámetro opcional ?folder= o ?path=
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Resolver y sanitizar directorio base multimedia (con permisos de escritura)
        $baseMediaDir = $this->resolveBaseMediaDir();
        $realBase = str_replace('\\', '/', realpath($baseMediaDir) ?: $baseMediaDir);

        // Si se solicita expresamente lista plana de todo el catálogo (retrocompatibilidad)
        if ($request->boolean('all') || $request->boolean('flat')) {
            $titles = Title::with(['seasons:id,title_id,season_number'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'titles' => $titles,
                'folders' => [],
                'breadcrumbs' => [['name' => 'Inicio', 'path' => '']],
                'current_folder' => '',
                'parent_folder' => null,
            ]);
        }

        // 2. Parámetro de ruta (folder o path) y sanitización estricta contra Directory Traversal
        $rawPath = (string) ($request->query('folder', $request->query('path', '')));
        $rawPath = trim(str_replace("\0", '', $rawPath));
        $rawPath = str_replace('\\', '/', $rawPath);
        $rawPath = trim($rawPath, '/');

        // Sanitización Directory Traversal: bloquear cualquier secuencia '..'
        if (preg_match('#(?:^|/)\.\.(?:/|$)#', $rawPath)) {
            return response()->json([
                'message' => 'Acceso denegado: intento de Directory Traversal detectado.',
            ], 403);
        }

        $parts = array_values(array_filter(explode('/', $rawPath), fn($p) => $p !== '' && $p !== '.'));
        $cleanPath = implode('/', $parts);

        // Validar que la ruta resuelta permanezca estrictamente dentro de $realBase
        $currentFullPath = $cleanPath !== '' ? $baseMediaDir . '/' . $cleanPath : $baseMediaDir;
        if (file_exists($currentFullPath)) {
            $realCurrent = realpath($currentFullPath);
            if ($realCurrent !== false) {
                $normCurrent = str_replace('\\', '/', $realCurrent);
                if (!str_starts_with(strtolower($normCurrent), strtolower($realBase))) {
                    return response()->json([
                        'message' => 'Acceso denegado: la ruta solicitada está fuera del almacenamiento multimedia.',
                    ], 403);
                }
            }
        }

        // 3. Construcción dinámica de Breadcrumbs
        $breadcrumbs = [
            ['name' => 'Inicio', 'path' => ''],
        ];
        $accum = [];
        foreach ($parts as $segment) {
            $accum[] = $segment;
            $subPath = implode('/', $accum);
            $displayName = match (strtolower($segment)) {
                'movies' => 'Películas',
                'series' => 'Series',
                default => null,
            };
            if (!$displayName) {
                $foundCat = Category::where('slug', $segment)
                    ->orWhere('folder_path', $segment)
                    ->orWhere('name', $segment)
                    ->first();
                $displayName = $foundCat ? $foundCat->name : ucfirst(str_replace(['-', '_'], ' ', $segment));
            }
            $breadcrumbs[] = [
                'name' => $displayName,
                'path' => $subPath,
            ];
        }

        // 4. Determinar carpeta padre para botón "Subir un nivel"
        $parentFolder = null;
        if (count($parts) > 1) {
            $parentFolder = implode('/', array_slice($parts, 0, count($parts) - 1));
        } elseif (count($parts) === 1) {
            $parentFolder = '';
        }

        // 5. Función auxiliar para conteo seguro de elementos válidos en directorios físicos
        $videoExtensions = ['mp4', 'mkv', 'webm', 'avi', 'mov', 'm3u8', 'ts'];
        $countPhysicalItems = function (string $dirPath) use ($videoExtensions): int {
            if (!is_dir($dirPath)) return 0;
            $entries = @scandir($dirPath) ?: [];
            $count = 0;
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..' || str_starts_with($e, '.')) continue;
                $p = $dirPath . '/' . $e;
                if (is_dir($p)) {
                    $count++;
                } elseif (is_file($p)) {
                    $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                    if (in_array($ext, $videoExtensions)) {
                        $count++;
                    }
                }
            }
            return $count;
        };

        // 6. Detección de carpetas y archivos para el nivel solicitado
        $folders = [];
        $titles = collect();

        if ($cleanPath === '') {
            // ==========================================
            // NIVEL RAÍZ (Inicio)
            // ==========================================
            $movieCount = Title::where('type', 'movie')->count();
            $seriesCount = Title::where('type', 'series')->count();

            $folders[] = [
                'name' => 'Películas',
                'raw_name' => 'movies',
                'relative_path' => 'movies',
                'path' => 'movies',
                'items_count' => $movieCount,
                'type' => 'library',
                'category_id' => null,
                'is_physical' => is_dir($baseMediaDir . '/movies'),
                'can_edit' => false,
                'can_delete' => false,
            ];
            $folders[] = [
                'name' => 'Series',
                'raw_name' => 'series',
                'relative_path' => 'series',
                'path' => 'series',
                'items_count' => $seriesCount,
                'type' => 'library',
                'category_id' => null,
                'is_physical' => is_dir($baseMediaDir . '/series'),
                'can_edit' => false,
                'can_delete' => false,
            ];

            // Subcarpetas físicas adicionales en raíz (si existieran)
            if (is_dir($baseMediaDir)) {
                $dirEntries = @scandir($baseMediaDir) ?: [];
                foreach ($dirEntries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $fullEntry = $baseMediaDir . '/' . $entry;
                    if (is_dir($fullEntry)) {
                        $lower = strtolower($entry);
                        if ($lower === 'movies' || $lower === 'series' || $lower === 'peliculas') {
                            continue;
                        }
                        $folders[] = [
                            'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'raw_name' => $entry,
                            'relative_path' => $entry,
                            'path' => $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
                            'category_id' => null,
                            'is_physical' => true,
                            'can_edit' => true,
                            'can_delete' => true,
                        ];
                    } elseif (is_file($fullEntry)) {
                        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                        if (in_array($ext, $videoExtensions)) {
                            $titles->push($this->wrapPhysicalFileAsTitle($fullEntry, $entry, $baseMediaDir));
                        }
                    }
                }
            }

            // C. Títulos descargados e ingesta local On-Net (para que aparezcan directamente en la vista raíz de Biblioteca)
            $downloadedTitles = Title::with(['seasons:id,title_id,season_number'])
                ->where(function ($q) {
                    $q->where('provider', '!=', 'local')
                      ->orWhere('stream_path', 'LIKE', '%/FlixHN/%')
                      ->orWhere('source_path', 'LIKE', '%/FlixHN/%')
                      ->orWhere('stream_path', 'LIKE', '%storage/media%')
                      ->orWhere('source_path', 'LIKE', '%storage/media%');
                })
                ->orderBy('created_at', 'desc')
                ->take(50)
                ->get();

            $existingIds = $titles->pluck('id')->filter()->flip();
            foreach ($downloadedTitles as $dt) {
                if (!isset($existingIds[$dt->id])) {
                    $titles->push($dt);
                    $existingIds[$dt->id] = true;
                }
            }
        } elseif ($cleanPath === 'movies' || $cleanPath === 'peliculas') {
            // ==========================================
            // NIVEL: Películas
            // ==========================================
            // A. Subcarpetas físicas en /var/media/movies
            $moviesDir = $baseMediaDir . '/movies';
            if (is_dir($moviesDir)) {
                $dirEntries = @scandir($moviesDir) ?: [];
                foreach ($dirEntries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $fullEntry = $moviesDir . '/' . $entry;
                    if (is_dir($fullEntry)) {
                        $matchedCat = Category::where('folder_path', $entry)
                            ->orWhere('slug', $entry)
                            ->orWhere('name', $entry)
                            ->first();
                        $folders[] = [
                            'name' => $matchedCat ? $matchedCat->name : ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'raw_name' => $entry,
                            'relative_path' => 'movies/' . $entry,
                            'path' => 'movies/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
                            'category_id' => $matchedCat?->id,
                            'is_physical' => true,
                            'can_edit' => true,
                            'can_delete' => true,
                        ];
                    } elseif (is_file($fullEntry)) {
                        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                        if (in_array($ext, $videoExtensions)) {
                            $titles->push($this->wrapPhysicalFileAsTitle($fullEntry, 'movies/' . $entry, $baseMediaDir));
                        }
                    }
                }
            }

            // B. Categorías / Géneros de películas del catálogo
            $movieCategories = Category::withCount(['titles' => function ($q) {
                $q->where('type', 'movie');
            }])
            ->where(function ($q) {
                $q->where('type', 'movie')->orWhere('type', 'mixed');
            })
            ->orderBy('sort_order', 'asc')
            ->get();

            $existingPaths = array_map(fn($f) => strtolower($f['relative_path']), $folders);

            foreach ($movieCategories as $cat) {
                $subRel = 'movies/' . ($cat->folder_path ?: $cat->slug);
                if (in_array(strtolower($subRel), $existingPaths)) {
                    continue;
                }

                $titleCount = $cat->titles_count ?: Title::where('type', 'movie')
                    ->where(function ($q) use ($cat) {
                        $q->where('category_id', $cat->id)->orWhere('genre', $cat->name);
                    })->count();

                if ($titleCount > 0 || is_dir($baseMediaDir . '/' . $subRel)) {
                    $folders[] = [
                        'name' => $cat->name,
                        'raw_name' => $cat->name,
                        'relative_path' => $subRel,
                        'path' => $subRel,
                        'items_count' => $titleCount,
                        'type' => 'category',
                        'category_id' => $cat->id,
                        'is_physical' => is_dir($baseMediaDir . '/' . $subRel),
                        'can_edit' => true,
                        'can_delete' => true,
                    ];
                }
            }

            // Películas categorizadas o descargadas
            $movieTitles = Title::with(['seasons:id,title_id,season_number'])
                ->where('type', 'movie')
                ->where(function ($q) {
                    $q->whereNull('category_id')
                      ->orWhere('provider', '!=', 'local')
                      ->orWhere('stream_path', 'LIKE', '%storage/media%')
                      ->orWhere('stream_path', 'LIKE', 'movies/%');
                })
                ->whereNotIn('id', $titles->pluck('id')->filter()->toArray())
                ->orderBy('created_at', 'desc')
                ->take(100)
                ->get();
            $titles = $titles->concat($movieTitles);

        } elseif ($cleanPath === 'series') {
            // ==========================================
            // NIVEL: Series
            // ==========================================
            // A. Subcarpetas físicas en /var/media/series
            $seriesDir = $baseMediaDir . '/series';
            if (is_dir($seriesDir)) {
                $dirEntries = @scandir($seriesDir) ?: [];
                foreach ($dirEntries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $fullEntry = $seriesDir . '/' . $entry;
                    if (is_dir($fullEntry)) {
                        $matchedCat = Category::where('folder_path', $entry)
                            ->orWhere('slug', $entry)
                            ->orWhere('name', $entry)
                            ->first();
                        $folders[] = [
                            'name' => $matchedCat ? $matchedCat->name : ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'raw_name' => $entry,
                            'relative_path' => 'series/' . $entry,
                            'path' => 'series/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
                            'category_id' => $matchedCat?->id,
                            'is_physical' => true,
                            'can_edit' => true,
                            'can_delete' => true,
                        ];
                    }
                }
            }

            // B. Categorías de Series del catálogo
            $seriesCategories = Category::withCount(['titles' => function ($q) {
                $q->where('type', 'series');
            }])
            ->where(function ($q) {
                $q->where('type', 'series')->orWhere('type', 'mixed');
            })
            ->orderBy('sort_order', 'asc')
            ->get();

            $existingPaths = array_map(fn($f) => strtolower($f['relative_path']), $folders);

            foreach ($seriesCategories as $cat) {
                $subRel = 'series/' . ($cat->folder_path ?: $cat->slug);
                if (in_array(strtolower($subRel), $existingPaths)) {
                    continue;
                }

                $titleCount = $cat->titles_count ?: Title::where('type', 'series')
                    ->where(function ($q) use ($cat) {
                        $q->where('category_id', $cat->id)->orWhere('genre', $cat->name);
                    })->count();

                if ($titleCount > 0 || is_dir($baseMediaDir . '/' . $subRel)) {
                    $folders[] = [
                        'name' => $cat->name,
                        'raw_name' => $cat->name,
                        'relative_path' => $subRel,
                        'path' => $subRel,
                        'items_count' => $titleCount,
                        'type' => 'category',
                        'category_id' => $cat->id,
                        'is_physical' => is_dir($baseMediaDir . '/' . $subRel),
                        'can_edit' => true,
                        'can_delete' => true,
                    ];
                }
            }

            // Series del catálogo o descargadas
            $seriesTitles = Title::with(['seasons:id,title_id,season_number'])
                ->where('type', 'series')
                ->where(function ($q) {
                    $q->whereNull('category_id')
                      ->orWhere('provider', '!=', 'local')
                      ->orWhere('stream_path', 'LIKE', '%storage/media%')
                      ->orWhere('stream_path', 'LIKE', 'series/%');
                })
                ->whereNotIn('id', $titles->pluck('id')->filter()->toArray())
                ->orderBy('created_at', 'desc')
                ->take(100)
                ->get();
            $titles = $titles->concat($seriesTitles);

        } else {
            // ==========================================
            // NIVEL: Subcarpetas intermedias o profundas
            // ==========================================
            $lastSegment = end($parts);
            $parentSegment = count($parts) > 1 ? $parts[count($parts) - 2] : null;

            // 1. Subcarpetas y archivos físicos reales en el directorio
            if (is_dir($currentFullPath)) {
                $dirEntries = @scandir($currentFullPath) ?: [];
                foreach ($dirEntries as $entry) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $fullEntry = $currentFullPath . '/' . $entry;
                    if (is_dir($fullEntry)) {
                        $folders[] = [
                            'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'raw_name' => $entry,
                            'relative_path' => $cleanPath . '/' . $entry,
                            'path' => $cleanPath . '/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
                            'category_id' => null,
                            'is_physical' => true,
                            'can_edit' => true,
                            'can_delete' => true,
                        ];
                    } elseif (is_file($fullEntry)) {
                        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                        if (in_array($ext, $videoExtensions)) {
                            $titles->push($this->wrapPhysicalFileAsTitle($fullEntry, $cleanPath . '/' . $entry, $baseMediaDir));
                        }
                    }
                }
            }

            // 2. Buscar categoría o género asociado al segmento
            $matchedCategory = Category::where('slug', $lastSegment)
                ->orWhere('folder_path', $lastSegment)
                ->orWhere('name', $lastSegment)
                ->first();

            $dbTitlesQuery = Title::with(['seasons:id,title_id,season_number']);

            if ($matchedCategory) {
                $dbTitlesQuery->where(function ($q) use ($matchedCategory, $cleanPath, $lastSegment) {
                    $q->where('category_id', $matchedCategory->id)
                      ->orWhere('genre', $matchedCategory->name)
                      ->orWhere('stream_path', 'LIKE', '%' . $lastSegment . '/%')
                      ->orWhere('source_path', 'LIKE', '%' . $lastSegment . '%');
                });
            } else {
                $dbTitlesQuery->where(function ($q) use ($cleanPath, $lastSegment) {
                    $q->where('genre', 'LIKE', '%' . $lastSegment . '%')
                      ->orWhere('name', 'LIKE', '%' . $lastSegment . '%')
                      ->orWhere('stream_path', 'LIKE', '%' . $cleanPath . '%')
                      ->orWhere('source_path', 'LIKE', '%' . $cleanPath . '%');
                });
            }

            if ($parentSegment === 'movies' || $parts[0] === 'movies' || $parts[0] === 'peliculas') {
                $dbTitlesQuery->where('type', 'movie');
            } elseif ($parentSegment === 'series' || $parts[0] === 'series') {
                $dbTitlesQuery->where('type', 'series');
            }

            $existingIds = $titles->pluck('id')->filter()->toArray();
            $dbTitles = $dbTitlesQuery->orderBy('name', 'asc')->get();

            foreach ($dbTitles as $dbt) {
                if (!in_array($dbt->id, $existingIds)) {
                    $titles->push($dbt);
                }
            }
        }

        return response()->json([
            'titles' => $titles->values(),
            'folders' => $folders,
            'current_folder' => $cleanPath,
            'breadcrumbs' => $breadcrumbs,
            'parent_folder' => $parentFolder,
            'total_titles' => $titles->count(),
            'total_folders' => count($folders),
        ]);
    }

    /**
     * Mapear un archivo físico de almacenamiento como estructura equivalente a Title.
     */
    protected function wrapPhysicalFileAsTitle(string $fullPath, string $relativePath, string $baseMediaDir): mixed
    {
        $filename = basename($fullPath);
        $baseNameNoExt = pathinfo($filename, PATHINFO_FILENAME);

        // Buscar si ya existe en la base de datos por file_path, stream_path, source_path o nombre
        $existing = Title::with(['seasons:id,title_id,season_number'])
            ->where('file_path', 'LIKE', '%' . $relativePath . '%')
            ->orWhere('stream_path', 'LIKE', '%' . $relativePath . '%')
            ->orWhere('source_path', 'LIKE', '%' . $relativePath . '%')
            ->orWhere('name', $baseNameNoExt)
            ->first();

        if ($existing) {
            return $existing;
        }

        $type = str_contains(strtolower($relativePath), 'serie') ? 'series' : 'movie';
        $categoryName = str_contains(strtolower($relativePath), 'movies') ? 'Películas' : (str_contains(strtolower($relativePath), 'series') ? 'Series' : 'Local');

        return [
            'id' => 'file-' . substr(md5($relativePath), 0, 10),
            'name' => $baseNameNoExt,
            'slug' => Str::slug($baseNameNoExt) . '-' . substr(md5($relativePath), 0, 4),
            'type' => $type,
            'genre' => $categoryName,
            'release_year' => (int) date('Y'),
            'description' => "Archivo multimedia local detectado en {$relativePath}",
            'poster_url' => url('/api/media/poster?title=' . urlencode($baseNameNoExt)),
            'backdrop_url' => url('/api/media/backdrop?title=' . urlencode($baseNameNoExt)),
            'is_featured' => false,
            'stream_path' => $relativePath,
            'source_path' => 'local://' . $relativePath,
            'file_path' => str_replace('\\', '/', $fullPath),
            'is_physical_file' => true,
            'duration_seconds' => 0,
            'seasons' => [],
            'title' => $baseNameNoExt,
            'overview' => "Archivo multimedia local detectado en {$relativePath}",
            'stream_url' => url('/media/' . ltrim($relativePath, '/')),
            'year' => (int) date('Y'),
            'is_new' => true,
        ];
    }

    /**
     * Crear un nuevo título (Película o Serie).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:movie,series',
            'description' => 'required|string',
            'poster_url' => 'required|string',
            'backdrop_url' => 'required|string',
            'release_year' => 'required|integer|min:1900|max:2099',
            'genre' => 'required|string|max:100',
            'is_featured' => 'boolean',
            'stream_path' => 'nullable|string',
            'duration_seconds' => 'nullable|integer',
        ]);

        $validated['slug'] = Str::slug($validated['name']) . '-' . rand(100, 999);
        $validated['is_featured'] = $validated['is_featured'] ?? false;

        $title = Title::create($validated);

        return response()->json([
            'message' => 'Título creado correctamente.',
            'title' => $title,
        ], 201);
    }

    /**
     * Actualizar título existente (o renombrar archivo físico no indexado).
     */
    public function update(Request $request, string|int $id): JsonResponse
    {
        if (is_string($id) && str_starts_with($id, 'file-')) {
            $rawPath = (string) ($request->input('source_path') ?: $request->input('stream_path') ?: $request->input('file_path') ?: '');
            $newName = trim((string) $request->input('name', ''));
            if ($newName === '' || !$this->isValidFilesystemName($newName)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nombre de archivo inválido. No incluyas barras ni caracteres prohibidos.',
                ], 422);
            }

            $resolvedFile = $this->resolveVirtualFilePath($id, $rawPath);
            if (!$resolvedFile || !is_file($resolvedFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo físico no encontrado en el servidor.',
                ], 404);
            }

            $ext = pathinfo($resolvedFile, PATHINFO_EXTENSION);
            $cleanBase = pathinfo($newName, PATHINFO_FILENAME);
            if ($cleanBase === '') {
                $cleanBase = $newName;
            }
            $targetFile = dirname($resolvedFile) . '/' . ($ext !== '' ? "{$cleanBase}.{$ext}" : $cleanBase);

            if (strtolower($targetFile) !== strtolower($resolvedFile) && file_exists($targetFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un archivo con ese nombre en esta carpeta.',
                ], 409);
            }

            if (!$this->isSafeMediaPath($resolvedFile) || !$this->isSafeMediaPath($targetFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: ruta fuera del almacenamiento permitido.',
                ], 403);
            }

            if (!@rename($resolvedFile, $targetFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo renombrar el archivo físico en el servidor. Verifica permisos.',
                ], 500);
            }

            app(\App\Services\MediaIntegrityService::class)->clearCatalogCache();

            return response()->json([
                'success' => true,
                'message' => 'Archivo renombrado correctamente en el servidor.',
            ]);
        }

        $title = Title::findOrFail((int) $id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:movie,series',
            'description' => 'sometimes|required|string',
            'poster_url' => 'sometimes|required|string',
            'backdrop_url' => 'sometimes|required|string',
            'release_year' => 'sometimes|required|integer',
            'genre' => 'sometimes|required|string',
            'is_featured' => 'boolean',
            'stream_path' => 'nullable|string',
            'duration_seconds' => 'nullable|integer',
        ]);

        if (isset($validated['name']) && $validated['name'] !== $title->name) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . rand(100, 999);
        }

        $title->update($validated);
        app(\App\Services\MediaIntegrityService::class)->clearCatalogCache();

        return response()->json([
            'success' => true,
            'message' => 'Título actualizado exitosamente.',
            'title' => $title->fresh(['seasons.episodes']),
        ]);
    }

    /**
     * Eliminar título del catálogo y sus archivos físicos reales en el servidor.
     */
    public function destroy(Request $request, string|int $id): JsonResponse
    {
        $integrityService = app(\App\Services\MediaIntegrityService::class);

        // Caso 1: Archivo físico no indexado en BD (ID virtual 'file-...')
        if (is_string($id) && str_starts_with($id, 'file-')) {
            $rawPath = (string) (
                $request->input('path')
                ?: $request->query('path')
                ?: $request->input('file_path')
                ?: $request->query('file_path')
                ?: $request->input('source_path')
                ?: $request->query('source_path')
                ?: $request->input('stream_path')
                ?: $request->query('stream_path')
                ?: ''
            );

            $resolvedFile = $this->resolveVirtualFilePath($id, $rawPath);
            if (!$resolvedFile || !file_exists($resolvedFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el archivo físico en el servidor para eliminar.',
                ], 404);
            }

            if (!$this->isSafeMediaPath($resolvedFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: la ruta está fuera del almacenamiento multimedia permitido.',
                ], 403);
            }

            $parentDir = dirname($resolvedFile);
            $wasDir = is_dir($resolvedFile);
            $deletedFile = $wasDir
                ? $this->deleteDirectoryForcefully($resolvedFile)
                : $this->deleteFileForcefully($resolvedFile);

            if (!$deletedFile) {
                return response()->json([
                    'success' => false,
                    'message' => "No se pudo eliminar el archivo del servidor ({$resolvedFile}). Verifica los permisos de escritura.",
                ], 500);
            }

            $companionsDeleted = $wasDir ? 0 : $this->deleteCompanionFiles($resolvedFile);
            $dirRemoved = $this->cleanupDedicatedEmptyParentDir($parentDir);

            // Purgar cualquier registro residual en BD que apuntara a ese archivo
            $baseName = basename($resolvedFile);
            try {
                $matchedTitles = Title::where('source_path', 'LIKE', '%' . $baseName)
                    ->orWhere('file_path', 'LIKE', '%' . $baseName)
                    ->get();
                foreach ($matchedTitles as $mt) {
                    $mtPath = $this->normalizeWritablePath((string) ($mt->file_path ?: $mt->source_path));
                    if (strtolower($mtPath) === strtolower($this->normalizeWritablePath($resolvedFile))) {
                        $integrityService->purgeTitle($mt);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AdminTitleController::destroy(file) cleanup warning: ' . $e->getMessage());
            }

            $integrityService->clearCatalogCache();

            return response()->json([
                'success' => true,
                'deleted' => true,
                'id' => $id,
                'physical_deleted' => true,
                'files_deleted' => 1 + $companionsDeleted,
                'dirs_deleted' => $dirRemoved ? 1 : 0,
                'message' => 'Archivo eliminado definitivamente del servidor.',
            ]);
        }

        // Caso 2: Título registrado en la base de datos
        $title = Title::with(['episodes', 'seasons'])->find($id);
        if (!$title) {
            return response()->json([
                'success' => false,
                'message' => 'Título no encontrado en el catálogo.',
            ], 404);
        }

        $titleName = $title->name;
        $titleId = $title->id;
        $mediaType = $title->type ?? 'movie';

        // 1. Eliminar todos los archivos y carpetas físicas del título en el servidor ANTES de borrar los registros de BD
        $physical = $this->deletePhysicalAssetsForTitle($title);

        if (!empty($physical['errors']) && $physical['files_deleted'] === 0 && $physical['dirs_deleted'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudieron eliminar los archivos del servidor: ' . implode(' | ', $physical['errors']),
                'errors' => $physical['errors'],
            ], 500);
        }

        // 2. Limpiar registros relacionados en MediaItem, MediaIngestTask, OttDownloadTask y DownloadJob
        try {
            if (Schema::hasTable('media_items')) {
                MediaItem::where('title_id', $titleId)->orWhere('title', $titleName)->delete();
            }
        } catch (\Throwable $e) {
            Log::debug('destroy MediaItem cleanup: ' . $e->getMessage());
        }

        try {
            if (Schema::hasTable('media_ingest_tasks')) {
                MediaIngestTask::where('title_id', $titleId)->orWhere('title', $titleName)->delete();
            }
        } catch (\Throwable $e) {
            Log::debug('destroy MediaIngestTask cleanup: ' . $e->getMessage());
        }

        try {
            if (Schema::hasTable('ott_download_tasks')) {
                OttDownloadTask::where('title_id', $titleId)->orWhere('title', $titleName)->delete();
            }
        } catch (\Throwable $e) {
            Log::debug('destroy OttDownloadTask cleanup: ' . $e->getMessage());
        }

        try {
            if (Schema::hasTable('download_jobs')) {
                DownloadJob::where('title_id', $titleId)->orWhere('title', $titleName)->delete();
            }
        } catch (\Throwable $e) {
            Log::debug('destroy DownloadJob cleanup: ' . $e->getMessage());
        }

        // 3. Purgar título, temporadas, episodios, progreso y caché
        $integrityService->purgeTitle($title);

        $diskNote = ($physical['files_deleted'] > 0 || $physical['dirs_deleted'] > 0)
            ? " ({$physical['files_deleted']} archivo(s) y {$physical['dirs_deleted']} carpeta(s) eliminados del servidor)"
            : ' (registro eliminado del catálogo)';

        return response()->json([
            'success' => true,
            'deleted' => true,
            'id' => $titleId,
            'type' => $mediaType,
            'files_deleted' => $physical['files_deleted'],
            'dirs_deleted' => $physical['dirs_deleted'],
            'warnings' => $physical['errors'],
            'message' => "Título \"{$titleName}\" eliminado definitivamente{$diskNote}.",
        ]);
    }

    /**
     * Renombrar una carpeta física y/o categoría de la biblioteca en el servidor.
     * PUT / POST /api/admin/library/folders/rename
     */
    public function renameFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500',
            'new_name' => 'required|string|max:150',
            'type' => 'nullable|string|max:40',
            'category_id' => 'nullable|integer',
        ]);

        $cleanOldPath = $this->sanitizeRelativePath($validated['path']);
        if ($cleanOldPath === null || $cleanOldPath === '') {
            return response()->json([
                'success' => false,
                'message' => 'Ruta de carpeta inválida o no permitida.',
            ], 422);
        }

        $lowerOld = strtolower($cleanOldPath);
        if (in_array($lowerOld, ['movies', 'series', 'peliculas'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Las bibliotecas raíz del sistema (Películas y Series) no se pueden renombrar.',
            ], 403);
        }

        $newName = trim($validated['new_name']);
        if (!$this->isValidFilesystemName($newName)) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre de carpeta inválido. Evita barras (/ \\) y caracteres especiales como : * ? " < > |.',
            ], 422);
        }

        $baseMediaDir = $this->resolveBaseMediaDir();
        $realBase = str_replace('\\', '/', realpath($baseMediaDir) ?: $baseMediaDir);

        $parts = explode('/', $cleanOldPath);
        $oldSegment = array_pop($parts);
        $parentRel = implode('/', $parts);
        $newRelPath = $parentRel !== '' ? "{$parentRel}/{$newName}" : $newName;

        $oldFullPath = $baseMediaDir . '/' . $cleanOldPath;
        $newFullPath = $baseMediaDir . '/' . $newRelPath;

        $physicalExists = is_dir($oldFullPath);
        $categoryId = $validated['category_id'] ?? null;
        $folderType = $validated['type'] ?? null;

        $category = null;
        if ($categoryId) {
            $category = Category::find($categoryId);
        } elseif ($folderType === 'category' || str_starts_with($lowerOld, 'movies/') || str_starts_with($lowerOld, 'series/')) {
            $category = Category::where('folder_path', $oldSegment)
                ->orWhere('slug', $oldSegment)
                ->orWhere('name', $oldSegment)
                ->first();
        }

        if (!$physicalExists && !$category) {
            return response()->json([
                'success' => false,
                'message' => 'La carpeta especificada no existe en el servidor ni en la base de datos.',
            ], 404);
        }

        $renamedPhysical = false;

        // 1. Renombrar carpeta física real en el servidor
        if ($physicalExists) {
            $realOld = realpath($oldFullPath);
            if ($realOld === false || !str_starts_with(strtolower(str_replace('\\', '/', $realOld)), strtolower($realBase))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: la carpeta está fuera del directorio multimedia.',
                ], 403);
            }

            if ($this->isProtectedPath($oldFullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta carpeta está protegida y no puede ser renombrada.',
                ], 403);
            }

            if (strtolower($oldFullPath) !== strtolower($newFullPath) && file_exists($newFullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Ya existe una carpeta llamada \"{$newName}\" en esta ubicación.",
                ], 409);
            }

            if ($oldFullPath !== $newFullPath) {
                $parentFull = dirname($newFullPath);
                if (!is_dir($parentFull)) {
                    @mkdir($parentFull, 0775, true);
                }

                // Soporte para cambio solo de mayúsculas/minúsculas en sistemas de archivos case-insensitive
                if (strtolower($oldFullPath) === strtolower($newFullPath)) {
                    $tmpPath = $oldFullPath . '__tmp_rename_' . uniqid();
                    $ok = @rename($oldFullPath, $tmpPath) && @rename($tmpPath, $newFullPath);
                } else {
                    $ok = @rename($oldFullPath, $newFullPath);
                }

                if (!$ok || !is_dir($newFullPath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se pudo renombrar la carpeta en el servidor. Verifica permisos de escritura.',
                    ], 500);
                }
                $renamedPhysical = true;

                // Actualizar rutas en BD para que streaming y carátulas sigan funcionando
                $this->updateDatabasePathsAfterFolderRename($baseMediaDir, $cleanOldPath, $newRelPath);
            }
        }

        // 2. Actualizar registro Category en BD si aplica
        if ($category) {
            $oldCatName = $category->name;
            $baseSlug = Str::slug($newName) ?: ('cat-' . $category->id);
            $uniqueSlug = $baseSlug;
            $suffix = 1;
            while (Category::where('slug', $uniqueSlug)->where('id', '!=', $category->id)->exists()) {
                $uniqueSlug = "{$baseSlug}-{$suffix}";
                $suffix++;
            }

            $category->name = $newName;
            $category->folder_path = $newName;
            $category->slug = $uniqueSlug;
            $category->save();

            // Actualizar el campo genre de los títulos asociados si coincidía con el nombre anterior
            try {
                Title::where('category_id', $category->id)
                    ->where('genre', $oldCatName)
                    ->update(['genre' => $newName]);
            } catch (\Throwable $e) {
                Log::debug('renameFolder title genre sync: ' . $e->getMessage());
            }
        }

        app(\App\Services\MediaIntegrityService::class)->clearCatalogCache();

        return response()->json([
            'success' => true,
            'old_path' => $cleanOldPath,
            'new_path' => $newRelPath,
            'new_name' => $newName,
            'physical_renamed' => $renamedPhysical,
            'message' => "Carpeta renombrada correctamente a \"{$newName}\".",
        ]);
    }

    /**
     * Eliminar una carpeta física y/o categoría completa del servidor y de la base de datos.
     * DELETE /api/admin/library/folders  |  POST /api/admin/library/folders/delete
     */
    public function deleteFolder(Request $request): JsonResponse
    {
        $rawPath = (string) ($request->input('path') ?: $request->query('path') ?: '');
        $folderType = $request->input('type') ?: $request->query('type');
        $categoryId = $request->input('category_id') ?: $request->query('category_id');

        $cleanPath = $this->sanitizeRelativePath($rawPath);
        if ($cleanPath === null || $cleanPath === '') {
            return response()->json([
                'success' => false,
                'message' => 'Ruta de carpeta inválida o vacía.',
            ], 422);
        }

        $lowerPath = strtolower($cleanPath);
        if (in_array($lowerPath, ['movies', 'series', 'peliculas'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden eliminar las bibliotecas raíz del sistema (Películas / Series).',
            ], 403);
        }

        $baseMediaDir = $this->resolveBaseMediaDir();
        $realBase = str_replace('\\', '/', realpath($baseMediaDir) ?: $baseMediaDir);
        $fullPath = $baseMediaDir . '/' . $cleanPath;

        $physicalExists = is_dir($fullPath);

        $parts = explode('/', $cleanPath);
        $lastSegment = end($parts);
        $category = null;
        if ($categoryId) {
            $category = Category::find((int) $categoryId);
        } elseif ($folderType === 'category' || str_starts_with($lowerPath, 'movies/') || str_starts_with($lowerPath, 'series/')) {
            $category = Category::where('folder_path', $lastSegment)
                ->orWhere('slug', $lastSegment)
                ->orWhere('name', $lastSegment)
                ->first();
        }

        if (!$physicalExists && !$category) {
            return response()->json([
                'success' => false,
                'message' => 'La carpeta especificada no existe en el servidor.',
            ], 404);
        }

        if ($physicalExists) {
            $realTarget = realpath($fullPath);
            if ($realTarget === false || !str_starts_with(strtolower(str_replace('\\', '/', $realTarget)), strtolower($realBase))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: intento de eliminación fuera del almacenamiento multimedia.',
                ], 403);
            }

            if ($this->isProtectedPath($fullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta carpeta del sistema está protegida y no puede ser eliminada.',
                ], 403);
            }
        }

        $integrityService = app(\App\Services\MediaIntegrityService::class);
        $titlesToPurge = collect();

        // 1. Localizar todos los títulos en BD asociados a esta carpeta física
        if ($physicalExists) {
            $normFull = rtrim(str_replace('\\', '/', $fullPath), '/');
            $varMediaFull = '/var/media/' . $cleanPath;

            $matchedByPath = Title::with(['episodes', 'seasons'])
                ->where(function ($q) use ($normFull, $varMediaFull, $cleanPath) {
                    $q->where('file_path', $normFull)
                      ->orWhere('file_path', 'LIKE', $normFull . '/%')
                      ->orWhere('source_path', $normFull)
                      ->orWhere('source_path', 'LIKE', $normFull . '/%')
                      ->orWhere('source_path', 'LIKE', 'local://' . $cleanPath . '/%')
                      ->orWhere('source_path', 'LIKE', $varMediaFull . '/%')
                      ->orWhere('file_path', 'LIKE', '%storage/media/' . $cleanPath . '/%')
                      ->orWhere('source_path', 'LIKE', '%storage/media/' . $cleanPath . '/%')
                      ->orWhere('stream_path', 'LIKE', $cleanPath . '/%')
                      ->orWhere('stream_path', 'LIKE', '%' . $normFull . '/%')
                      ->orWhere('stream_path', 'LIKE', '%' . $varMediaFull . '/%');
                })
                ->get();

            $titlesToPurge = $titlesToPurge->merge($matchedByPath);

            // También buscar títulos cuyos episodios o MediaItems residan dentro de esta carpeta
            try {
                $epTitleIds = Episode::where('file_path', 'LIKE', $normFull . '/%')
                    ->orWhere('source_path', 'LIKE', $normFull . '/%')
                    ->orWhere('file_path', 'LIKE', $varMediaFull . '/%')
                    ->pluck('title_id')
                    ->filter()
                    ->unique()
                    ->toArray();

                if (!empty($epTitleIds)) {
                    $titlesToPurge = $titlesToPurge->merge(
                        Title::with(['episodes', 'seasons'])->whereIn('id', $epTitleIds)->get()
                    );
                }
            } catch (\Throwable $e) {
                Log::debug('deleteFolder episode lookup: ' . $e->getMessage());
            }

            try {
                if (Schema::hasTable('media_items')) {
                    $miTitleIds = MediaItem::where('file_path', $normFull)
                        ->orWhere('file_path', 'LIKE', $normFull . '/%')
                        ->orWhere('file_path', 'LIKE', $varMediaFull . '/%')
                        ->pluck('title_id')
                        ->filter()
                        ->unique()
                        ->toArray();

                    if (!empty($miTitleIds)) {
                        $titlesToPurge = $titlesToPurge->merge(
                            Title::with(['episodes', 'seasons'])->whereIn('id', $miTitleIds)->get()
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('deleteFolder media_items lookup: ' . $e->getMessage());
            }
        }

        // 2. Localizar títulos asociados a la Categoría (si es carpeta de categoría)
        $scopeType = null;
        if (str_starts_with($lowerPath, 'movies/') || str_starts_with($lowerPath, 'peliculas/')) {
            $scopeType = 'movie';
        } elseif (str_starts_with($lowerPath, 'series/')) {
            $scopeType = 'series';
        }

        if ($category) {
            $catQuery = Title::with(['episodes', 'seasons'])->where('category_id', $category->id);
            if ($scopeType) {
                $catQuery->where('type', $scopeType);
            }
            $titlesToPurge = $titlesToPurge->merge($catQuery->get());
        }

        // 3. Eliminar activos físicos de cada título encontrado y purgar de BD
        $titlesToPurge = $titlesToPurge->unique('id');
        $deletedTitlesCount = 0;

        foreach ($titlesToPurge as $title) {
            try {
                $this->deletePhysicalAssetsForTitle($title);
                $integrityService->purgeTitle($title);
                $deletedTitlesCount++;
            } catch (\Throwable $e) {
                Log::warning("deleteFolder: Error purgando título [{$title->id}]: " . $e->getMessage());
            }
        }

        // 4. Eliminar o ajustar registro de Categoría si corresponde
        if ($category) {
            try {
                $remainingTitles = Title::where('category_id', $category->id)->count();
                if ($remainingTitles === 0) {
                    $category->delete();
                } elseif ($scopeType === 'movie' && $category->type === 'mixed') {
                    $category->update(['type' => 'series']);
                } elseif ($scopeType === 'series' && $category->type === 'mixed') {
                    $category->update(['type' => 'movie']);
                }
            } catch (\Throwable $e) {
                Log::warning('deleteFolder category cleanup: ' . $e->getMessage());
            }
        }

        // 5. Eliminar físicamente la carpeta completa y todo su contenido del servidor
        $physicalDeleted = false;
        if ($physicalExists && is_dir($fullPath)) {
            $physicalDeleted = $this->deleteDirectoryForcefully($fullPath);
            if (!$physicalDeleted && file_exists($fullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "No se pudo eliminar la carpeta física del servidor ({$cleanPath}). Verifica los permisos del sistema de archivos.",
                ], 500);
            }
        }

        $integrityService->clearCatalogCache();

        return response()->json([
            'success' => true,
            'deleted' => true,
            'path' => $cleanPath,
            'physical_deleted' => $physicalDeleted,
            'deleted_titles_count' => $deletedTitlesCount,
            'message' => "Carpeta \"{$lastSegment}\" eliminada definitivamente del servidor"
                . ($deletedTitlesCount > 0 ? " junto con {$deletedTitlesCount} título(s) asociado(s)." : '.'),
        ]);
    }

    // =========================================================================
    // Helpers seguros de sistema de archivos para administración de biblioteca
    // =========================================================================

    /**
     * Resuelve el directorio base de almacenamiento multimedia priorizando una ruta con permisos de escritura.
     */
    protected function resolveBaseMediaDir(): string
    {
        $envDir = env('MEDIA_PATH');
        if ($envDir && is_dir($envDir)) {
            $normEnv = rtrim(str_replace('\\', '/', $envDir), '/');
            if ($normEnv === '/var/media' && !is_writable($normEnv) && is_dir(storage_path('media'))) {
                return str_replace('\\', '/', storage_path('media'));
            }
            return $normEnv;
        }

        // Preferir storage/media (en Docker comparte volumen con /var/media pero siempre en modo lectura/escritura)
        $storageMedia = storage_path('media');
        if (is_dir($storageMedia)) {
            return str_replace('\\', '/', $storageMedia);
        }

        if (is_dir('/var/media')) {
            return '/var/media';
        }

        $parentMedia = base_path('../media');
        if (is_dir($parentMedia)) {
            return str_replace('\\', '/', $parentMedia);
        }

        $baseMedia = base_path('media');
        if (is_dir($baseMedia)) {
            return str_replace('\\', '/', $baseMedia);
        }

        @mkdir($storageMedia, 0777, true);
        return str_replace('\\', '/', $storageMedia);
    }

    /**
     * Sanitiza una ruta relativa y bloquea cualquier secuencia de Directory Traversal.
     */
    protected function sanitizeRelativePath(string $rawPath): ?string
    {
        $clean = trim(str_replace("\0", '', $rawPath));
        $clean = str_replace('\\', '/', $clean);
        $clean = trim($clean, '/');

        if (preg_match('#(?:^|/)\.\.(?:/|$)#', $clean)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $clean), fn($p) => $p !== '' && $p !== '.'));
        return implode('/', $parts);
    }

    /**
     * Valida que un nombre de archivo o carpeta sea seguro para el sistema de archivos.
     */
    protected function isValidFilesystemName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 150) {
            return false;
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || str_contains($name, '..')) {
            return false;
        }
        return !preg_match('/[:*?"<>|]/', $name);
    }

    /**
     * Traduce rutas montadas en modo solo-lectura (ej. /var/media/...) hacia la ruta escribible equivalente (storage/media/...).
     */
    protected function normalizeWritablePath(string $absPath): string
    {
        $norm = rtrim(str_replace('\\', '/', trim($absPath)), '/');
        if ($norm === '') {
            return '';
        }

        if (($norm === '/var/media' || str_starts_with($norm, '/var/media/')) && is_dir(storage_path('media'))) {
            $rel = ltrim(substr($norm, strlen('/var/media')), '/');
            $storageTarget = rtrim(str_replace('\\', '/', storage_path('media')), '/') . ($rel !== '' ? '/' . $rel : '');
            if (file_exists($storageTarget) || !is_writable($norm)) {
                return $storageTarget;
            }
        }

        return $norm;
    }

    /**
     * Devuelve todas las raíces de almacenamiento multimedia autorizadas en el servidor.
     */
    protected function getAllowedMediaRoots(): array
    {
        $roots = [
            $this->resolveBaseMediaDir(),
            storage_path('media'),
            storage_path('app/public'),
            '/var/media',
            '/var/www/html/storage/media',
            '/var/www/html/storage/app/public',
            '/mnt/media_library',
            base_path('media'),
            base_path('../media'),
        ];

        $envDir = env('MEDIA_PATH');
        if (!empty($envDir)) {
            $roots[] = $envDir;
        }

        foreach (['/mnt/media_library/Disk*', '/home/server01/Disk*'] as $pattern) {
            $found = @glob($pattern) ?: [];
            foreach ($found as $d) {
                $roots[] = $d;
            }
        }

        try {
            $scanner = app(\App\Services\LocalMediaScannerService::class);
            foreach ($scanner->getConfiguredPaths() as $p) {
                if (!empty($p)) {
                    $roots[] = $p;
                }
            }
        } catch (\Throwable) {}

        $normalized = [];
        foreach ($roots as $r) {
            if (empty($r)) continue;
            $n = rtrim(str_replace('\\', '/', (string) $r), '/');
            if ($n !== '') {
                $normalized[] = $n;
                $real = @realpath($n);
                if ($real) {
                    $normalized[] = rtrim(str_replace('\\', '/', $real), '/');
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Determina si una ruta corresponde a un directorio raíz crítico del sistema o de almacenamiento.
     */
    protected function isProtectedPath(string $absPath): bool
    {
        $norm = rtrim(str_replace('\\', '/', $this->normalizeWritablePath($absPath)), '/');
        if ($norm === '' || $norm === '/' || preg_match('/^[a-zA-Z]:$/', $norm)) {
            return true;
        }

        $lower = strtolower($norm);

        $forbidden = [
            '/etc', '/root', '/bin', '/sbin', '/usr', '/boot', '/dev', '/proc', '/sys', '/run',
            '/var', '/var/www', '/var/www/html', '/var/www/html/storage', '/mnt', '/mnt/media_library',
            '/home', '/home/server01', '/storage',
            strtolower(rtrim(str_replace('\\', '/', base_path()), '/')),
            strtolower(rtrim(str_replace('\\', '/', storage_path()), '/')),
            strtolower(rtrim(str_replace('\\', '/', storage_path('app')), '/')),
            strtolower(rtrim(str_replace('\\', '/', storage_path('app/public')), '/')),
        ];

        foreach ($this->getAllowedMediaRoots() as $root) {
            $rLower = strtolower($root);
            $forbidden[] = $rLower;
            $forbidden[] = $rLower . '/movies';
            $forbidden[] = $rLower . '/series';
        }

        if (in_array($lower, $forbidden, true)) {
            return true;
        }

        if (preg_match('#^/(?:mnt/media_library|home/server01)/disk\d+$#i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Determina si un directorio es un contenedor compartido de alto nivel que no debe auto-eliminarse
     * al borrar un archivo individual.
     */
    protected function isProtectedContainerFolder(string $absPath): bool
    {
        if ($this->isProtectedPath($absPath)) {
            return true;
        }

        $base = strtolower(basename(rtrim(str_replace('\\', '/', $absPath), '/')));
        $sharedContainers = [
            'movies', 'series', 'peliculas', 'películas', 'estrenos', 'infantiles', 'anime',
            'netflix', 'disney', 'disney_plus', 'hbo_max', 'max', 'amazon_prime', 'prime',
            'apple_tv', 'paramount', 'flixhn', 'samples', 'hls_live', 'posters', 'backdrops',
            'logos', 'media', 'videos', 'downloads', 'descargas', 'peliculas umanet', 'animado umanet',
        ];

        return in_array($base, $sharedContainers, true);
    }

    /**
     * Verifica que una ruta absoluta sea segura y esté estrictamente dentro de las raíces multimedia autorizadas.
     */
    protected function isSafeMediaPath(string $absPath): bool
    {
        if ($absPath === '' || str_contains($absPath, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', str_replace('\\', '/', $absPath))) {
            return false;
        }

        $norm = $this->normalizeWritablePath($absPath);
        if ($this->isProtectedPath($norm)) {
            return false;
        }

        $checkPath = file_exists($norm) ? str_replace('\\', '/', realpath($norm) ?: $norm) : $norm;
        $lowerCheck = strtolower($checkPath);

        foreach ($this->getAllowedMediaRoots() as $root) {
            $lowerRoot = strtolower($root);
            if (str_starts_with($lowerCheck, $lowerRoot . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina físicamente un archivo del servidor asegurando permisos de escritura.
     */
    protected function deleteFileForcefully(string $filePath): bool
    {
        $target = $this->normalizeWritablePath($filePath);
        if (!file_exists($target) && !is_link($target)) {
            return true;
        }
        if (!$this->isSafeMediaPath($target)) {
            return false;
        }

        @chmod($target, 0666);
        @unlink($target);
        clearstatcache(true, $target);

        return !file_exists($target) && !is_link($target);
    }

    /**
     * Elimina físicamente un directorio y todo su contenido de forma recursiva del servidor.
     */
    protected function deleteDirectoryForcefully(string $dirPath): bool
    {
        $target = $this->normalizeWritablePath($dirPath);
        if (!file_exists($target)) {
            return true;
        }
        if (!$this->isSafeMediaPath($target) || $this->isProtectedPath($target)) {
            return false;
        }

        if (is_file($target) || is_link($target)) {
            return $this->deleteFileForcefully($target);
        }

        @chmod($target, 0777);
        $entries = @scandir($target) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $target . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->deleteDirectoryForcefully($child);
            } else {
                @chmod($child, 0666);
                @unlink($child);
            }
        }

        @rmdir($target);
        clearstatcache(true, $target);

        if (file_exists($target)) {
            try {
                File::deleteDirectory($target);
                clearstatcache(true, $target);
            } catch (\Throwable $e) {
                Log::warning("deleteDirectoryForcefully fallback error [{$target}]: " . $e->getMessage());
            }
        }

        return !file_exists($target);
    }

    /**
     * Elimina archivos acompañantes homónimos (.nfo, subtítulos, carátulas locales) de un archivo de video.
     */
    protected function deleteCompanionFiles(string $videoFilePath): int
    {
        $norm = $this->normalizeWritablePath($videoFilePath);
        $dir = dirname($norm);
        $baseName = pathinfo($norm, PATHINFO_FILENAME);

        if ($baseName === '' || !is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        $suffixes = [
            '.nfo', '.srt', '.vtt', '.ass', '.ssa', '.sub', '.idx',
            '.jpg', '.jpeg', '.png', '.webp',
            '-poster.jpg', '-poster.jpeg', '-poster.png', '-poster.webp',
            '_poster.jpg', '_poster.jpeg', '_poster.png', '_poster.webp',
            '-fanart.jpg', '-fanart.jpeg', '-fanart.png', '-fanart.webp',
            '-backdrop.jpg', '-backdrop.jpeg', '-backdrop.png', '-backdrop.webp',
            '-thumb.jpg', '-thumb.jpeg', '-thumb.png', '-thumb.webp',
            '-cover.jpg', '-cover.jpeg', '-cover.png', '-cover.webp',
        ];

        foreach ($suffixes as $suffix) {
            $cand = $dir . '/' . $baseName . $suffix;
            if (file_exists($cand) && is_file($cand) && $this->isSafeMediaPath($cand)) {
                if ($this->deleteFileForcefully($cand)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Comprueba si una carpeta contiene algún archivo de video (recursivamente).
     */
    protected function directoryContainsVideoFiles(string $dirPath): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }
        $videoExts = ['mp4', 'mkv', 'webm', 'avi', 'mov', 'm3u8', 'ts', 'm4v'];
        $entries = @scandir($dirPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $dirPath . '/' . $entry;
            if (is_file($child)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $videoExts, true)) {
                    return true;
                }
            } elseif (is_dir($child) && !is_link($child)) {
                if ($this->directoryContainsVideoFiles($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Si la carpeta padre es una carpeta dedicada a un único título y ya no quedan videos ni otros títulos en BD,
     * la elimina del servidor para no dejar carpetas vacías o residuales.
     */
    protected function cleanupDedicatedEmptyParentDir(string $dirPath, ?int $excludeTitleId = null): bool
    {
        $norm = $this->normalizeWritablePath($dirPath);
        if (!is_dir($norm) || $this->isProtectedContainerFolder($norm) || !$this->isSafeMediaPath($norm)) {
            return false;
        }

        if ($this->directoryContainsVideoFiles($norm)) {
            return false;
        }

        // Verificar que ningún otro Title en BD apunte a este directorio
        $otherQuery = Title::where(function ($q) use ($norm) {
            $q->where('source_path', 'LIKE', $norm . '%')
              ->orWhere('file_path', 'LIKE', $norm . '%');
        });
        if ($excludeTitleId) {
            $otherQuery->where('id', '!=', $excludeTitleId);
        }
        if ($otherQuery->exists()) {
            return false;
        }

        return $this->deleteDirectoryForcefully($norm);
    }

    /**
     * Resuelve un archivo virtual (ID 'file-...') a su ruta física real en el servidor.
     */
    protected function resolveVirtualFilePath(string $virtualId, string $rawPath = ''): ?string
    {
        $baseMediaDir = $this->resolveBaseMediaDir();
        $realBase = str_replace('\\', '/', realpath($baseMediaDir) ?: $baseMediaDir);

        if ($rawPath !== '') {
            $cleanRaw = preg_replace('#^local://#i', '', trim($rawPath));
            $candidates = $this->resolvePathCandidates($cleanRaw);
            foreach ($candidates as $cand) {
                if (file_exists($cand) && $this->isSafeMediaPath($cand)) {
                    return $cand;
                }
            }

            $sanitizedRel = $this->sanitizeRelativePath($cleanRaw);
            if ($sanitizedRel !== null && $sanitizedRel !== '') {
                $cand = $baseMediaDir . '/' . $sanitizedRel;
                if (file_exists($cand) && $this->isSafeMediaPath($cand)) {
                    return $this->normalizeWritablePath($cand);
                }
            }
        }

        // Fallback: localizar el archivo por su hash MD5 dentro de $baseMediaDir
        if (is_dir($realBase)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realBase, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $full = str_replace('\\', '/', $fileInfo->getPathname());
                $rel = ltrim(substr($full, strlen($realBase)), '/');
                if ('file-' . substr(md5($rel), 0, 10) === $virtualId) {
                    return $this->normalizeWritablePath($full);
                }
            }
        }

        return null;
    }

    /**
     * Expande una ruta guardada en BD a sus posibles ubicaciones reales existentes en disco.
     */
    protected function resolvePathCandidates(?string $rawPath): array
    {
        if (empty($rawPath)) {
            return [];
        }

        $trimmed = trim(str_replace('\\', '/', $rawPath));
        if (
            $trimmed === '' ||
            str_starts_with($trimmed, '/api/') ||
            str_starts_with($trimmed, 'http://') ||
            str_starts_with($trimmed, 'https://') ||
            str_starts_with($trimmed, 'remote://')
        ) {
            return [];
        }

        $trimmed = preg_replace('#^local://#i', '', $trimmed);
        $baseMediaDir = $this->resolveBaseMediaDir();
        $decoded = rawurldecode($trimmed);

        $rawCandidates = [
            $trimmed,
            $decoded,
            $this->normalizeWritablePath($trimmed),
            $this->normalizeWritablePath($decoded),
            $baseMediaDir . '/' . ltrim($trimmed, '/'),
            $baseMediaDir . '/' . ltrim($decoded, '/'),
            storage_path('media/' . ltrim($trimmed, '/')),
            storage_path('media/' . ltrim(preg_replace('#^/?(?:storage/media|media)/#i', '', $trimmed), '/')),
            storage_path('app/public/' . ltrim(preg_replace('#^/?storage/#i', '', $trimmed), '/')),
            public_path(ltrim($trimmed, '/')),
            base_path(ltrim($trimmed, '/')),
            '/var/www/html/' . ltrim($trimmed, '/'),
        ];

        $existing = [];
        foreach ($rawCandidates as $cand) {
            if (empty($cand)) continue;
            $norm = $this->normalizeWritablePath($cand);
            if ($norm !== '' && file_exists($norm)) {
                $existing[] = $norm;
            }
        }

        return array_values(array_unique($existing));
    }

    /**
     * Elimina todos los archivos y carpetas físicas asociadas a un Title (película o serie) del servidor.
     */
    protected function deletePhysicalAssetsForTitle(Title $title): array
    {
        $filesDeleted = 0;
        $dirsDeleted = 0;
        $deletedPaths = [];
        $errors = [];

        $rawPaths = [];
        $candidateDirsToCleanup = [];
        $hlsDirs = [];

        // 1. Rutas del título principal (usando getRawOriginal para evitar el accessor /api/stream/title/{id})
        foreach ([
            $title->file_path,
            $title->source_path,
            $title->getRawOriginal('stream_path'),
        ] as $p) {
            if (!empty($p)) {
                $rawPaths[] = $p;
            }
        }

        // 2. Rutas de episodios si es una serie
        if ($title->type === 'series') {
            $episodes = $title->relationLoaded('episodes')
                ? $title->episodes
                : Episode::where('title_id', $title->id)->get();

            foreach ($episodes as $ep) {
                foreach ([
                    $ep->file_path,
                    $ep->source_path,
                    $ep->getRawOriginal('stream_path'),
                ] as $epPath) {
                    if (!empty($epPath)) {
                        $rawPaths[] = $epPath;
                    }
                }
            }
        }

        // 3. Rutas en tablas auxiliares (MediaItem, MediaIngestTask, OttDownloadTask, DownloadJob)
        try {
            if (Schema::hasTable('media_items')) {
                $items = MediaItem::where('title_id', $title->id)->get();
                foreach ($items as $mi) {
                    if (!empty($mi->file_path)) {
                        $rawPaths[] = $mi->file_path;
                    }
                }
            }
        } catch (\Throwable) {}

        try {
            if (Schema::hasTable('media_ingest_tasks')) {
                $tasks = MediaIngestTask::where('title_id', $title->id)
                    ->orWhere('title', $title->name)
                    ->get();
                foreach ($tasks as $task) {
                    if (!empty($task->output_file)) {
                        $rawPaths[] = $task->output_file;
                    }
                }
            }
        } catch (\Throwable) {}

        try {
            if (Schema::hasTable('ott_download_tasks')) {
                $ottTasks = OttDownloadTask::where('title_id', $title->id)
                    ->orWhere('title', $title->name)
                    ->get();
                foreach ($ottTasks as $ot) {
                    if (!empty($ot->file_path)) {
                        $rawPaths[] = $ot->file_path;
                    }
                }
            }
        } catch (\Throwable) {}

        try {
            if (Schema::hasTable('download_jobs')) {
                $jobs = DownloadJob::where('title_id', $title->id)
                    ->orWhere('title', $title->name)
                    ->get();
                foreach ($jobs as $job) {
                    if (!empty($job->storage_path)) {
                        $rawPaths[] = $job->storage_path;
                    }
                    if (!empty($job->hls_path)) {
                        $hlsDirs[] = $job->hls_path;
                    }
                }
            }
        } catch (\Throwable) {}

        $rawPaths = array_values(array_unique(array_filter($rawPaths)));

        // 4. Eliminar archivos individuales y recolectar directorios dedicados
        $dedicatedDirs = [];
        foreach ($rawPaths as $raw) {
            foreach ($this->resolvePathCandidates($raw) as $resolved) {
                if (!$this->isSafeMediaPath($resolved)) {
                    continue;
                }

                if (is_dir($resolved)) {
                    if (!$this->isProtectedContainerFolder($resolved)) {
                        $dedicatedDirs[] = $resolved;
                    }
                } elseif (is_file($resolved) || is_link($resolved)) {
                    $parentDir = dirname($resolved);
                    if ($this->deleteFileForcefully($resolved)) {
                        $filesDeleted++;
                        $deletedPaths[] = $resolved;
                        $filesDeleted += $this->deleteCompanionFiles($resolved);
                        $candidateDirsToCleanup[] = $parentDir;
                        // En series, también evaluar carpeta abuela (ej. Serie/Season 1 -> Serie)
                        if ($title->type === 'series') {
                            $candidateDirsToCleanup[] = dirname($parentDir);
                        }
                    } else {
                        $errors[] = "Permiso denegado al eliminar archivo: {$resolved}";
                    }
                }
            }
        }

        // 5. Eliminar carpetas HLS temporales de DownloadJob si existen
        foreach (array_unique($hlsDirs) as $hlsPath) {
            foreach ($this->resolvePathCandidates($hlsPath) as $resolvedHls) {
                if (is_dir($resolvedHls) && $this->isSafeMediaPath($resolvedHls) && !$this->isProtectedContainerFolder($resolvedHls)) {
                    if ($this->deleteDirectoryForcefully($resolvedHls)) {
                        $dirsDeleted++;
                        $deletedPaths[] = $resolvedHls;
                    }
                }
            }
        }

        // 6. Eliminar directorios dedicados de la serie/película (solo si ningún otro Title los usa)
        foreach (array_unique($dedicatedDirs) as $dirPath) {
            if (!is_dir($dirPath)) continue;
            $otherUsing = Title::where('id', '!=', $title->id)
                ->where(function ($q) use ($dirPath) {
                    $q->where('source_path', 'LIKE', $dirPath . '%')
                      ->orWhere('file_path', 'LIKE', $dirPath . '%');
                })
                ->exists();
            if ($otherUsing) {
                continue;
            }

            if ($this->deleteDirectoryForcefully($dirPath)) {
                $dirsDeleted++;
                $deletedPaths[] = $dirPath;
            } else {
                $errors[] = "No se pudo eliminar la carpeta del servidor: {$dirPath}";
            }
        }

        // 7. Limpiar carpetas padre dedicadas que quedaron sin archivos de video
        // Ordenar de más profunda a menos profunda para limpiar primero Season 1 y luego la carpeta de la serie
        $candidateDirsToCleanup = array_values(array_unique($candidateDirsToCleanup));
        usort($candidateDirsToCleanup, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($candidateDirsToCleanup as $candDir) {
            if (is_dir($candDir) && $this->cleanupDedicatedEmptyParentDir($candDir, $title->id)) {
                $dirsDeleted++;
                $deletedPaths[] = $candDir;
            }
        }

        // 8. Eliminar carátulas locales almacenadas en storage/app/public si ningún otro título las usa
        foreach (['poster_url', 'backdrop_url'] as $artCol) {
            $rawArt = (string) ($title->getRawOriginal($artCol) ?? '');
            if (str_starts_with($rawArt, '/storage/posters/') || str_starts_with($rawArt, '/storage/backdrops/')) {
                $sharedArt = Title::where('id', '!=', $title->id)
                    ->where($artCol, $rawArt)
                    ->exists();
                if (!$sharedArt) {
                    $relArt = ltrim(preg_replace('#^/storage/#', '', $rawArt), '/');
                    $artFull = storage_path('app/public/' . $relArt);
                    if (is_file($artFull) && $this->isSafeMediaPath($artFull)) {
                        if ($this->deleteFileForcefully($artFull)) {
                            $filesDeleted++;
                        }
                    }
                }
            }
        }

        return [
            'files_deleted' => $filesDeleted,
            'dirs_deleted' => $dirsDeleted,
            'deleted_paths' => $deletedPaths,
            'errors' => $errors,
        ];
    }

    /**
     * Actualiza las rutas almacenadas en base de datos después de renombrar físicamente una carpeta.
     */
    protected function updateDatabasePathsAfterFolderRename(string $baseMediaDir, string $oldRelPath, string $newRelPath): void
    {
        $normBase = rtrim(str_replace('\\', '/', $baseMediaDir), '/');
        $oldFull = $normBase . '/' . $oldRelPath;
        $newFull = $normBase . '/' . $newRelPath;
        $oldVar = '/var/media/' . $oldRelPath;
        $newVar = '/var/media/' . $newRelPath;

        $replacements = [
            [$oldFull . '/', $newFull . '/'],
            [$oldVar . '/', $newVar . '/'],
            ['storage/media/' . $oldRelPath . '/', 'storage/media/' . $newRelPath . '/'],
            ['local://' . $oldRelPath . '/', 'local://' . $newRelPath . '/'],
            [urlencode($oldFull . '/'), urlencode($newFull . '/')],
        ];

        $replaceInValue = function (?string $val) use ($replacements, $oldFull, $newFull, $oldVar, $newVar, $oldRelPath, $newRelPath): ?string {
            if ($val === null || $val === '') return $val;
            $norm = str_replace('\\', '/', $val);
            if ($norm === $oldFull) return $newFull;
            if ($norm === $oldVar) return $newVar;
            if ($norm === 'local://' . $oldRelPath) return 'local://' . $newRelPath;
            if ($norm === $oldRelPath) return $newRelPath;

            $updated = $norm;
            foreach ($replacements as [$from, $to]) {
                if (str_contains($updated, $from)) {
                    $updated = str_replace($from, $to, $updated);
                }
            }
            if (str_starts_with($updated, $oldRelPath . '/')) {
                $updated = $newRelPath . '/' . substr($updated, strlen($oldRelPath . '/'));
            }
            return $updated;
        };

        // 1. Titles
        try {
            $titles = Title::where(function ($q) use ($oldFull, $oldVar, $oldRelPath) {
                $q->where('file_path', 'LIKE', '%' . $oldRelPath . '%')
                  ->orWhere('source_path', 'LIKE', '%' . $oldRelPath . '%')
                  ->orWhere('stream_path', 'LIKE', '%' . $oldRelPath . '%')
                  ->orWhere('poster_url', 'LIKE', '%' . $oldRelPath . '%')
                  ->orWhere('backdrop_url', 'LIKE', '%' . $oldRelPath . '%')
                  ->orWhere('source_path', 'LIKE', $oldFull . '%')
                  ->orWhere('source_path', 'LIKE', $oldVar . '%');
            })->get();

            foreach ($titles as $t) {
                $updates = [];
                foreach (['file_path', 'source_path', 'stream_path', 'poster_url', 'backdrop_url'] as $col) {
                    $orig = $t->getRawOriginal($col);
                    $next = $replaceInValue($orig);
                    if ($next !== $orig) {
                        $updates[$col] = $next;
                    }
                }
                if (!empty($updates)) {
                    DB::table('titles')->where('id', $t->id)->update($updates);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('updateDatabasePathsAfterFolderRename titles error: ' . $e->getMessage());
        }

        // 2. Episodes
        try {
            $episodes = Episode::where('file_path', 'LIKE', '%' . $oldRelPath . '%')
                ->orWhere('source_path', 'LIKE', '%' . $oldRelPath . '%')
                ->orWhere('stream_path', 'LIKE', '%' . $oldRelPath . '%')
                ->orWhere('thumbnail_url', 'LIKE', '%' . $oldRelPath . '%')
                ->get();

            foreach ($episodes as $ep) {
                $updates = [];
                foreach (['file_path', 'source_path', 'stream_path', 'thumbnail_url'] as $col) {
                    $orig = $ep->getRawOriginal($col);
                    $next = $replaceInValue($orig);
                    if ($next !== $orig) {
                        $updates[$col] = $next;
                    }
                }
                if (!empty($updates)) {
                    DB::table('episodes')->where('id', $ep->id)->update($updates);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('updateDatabasePathsAfterFolderRename episodes error: ' . $e->getMessage());
        }

        // 3. MediaItems
        try {
            if (Schema::hasTable('media_items')) {
                $items = MediaItem::where('file_path', 'LIKE', '%' . $oldRelPath . '%')
                    ->orWhere('poster_url', 'LIKE', '%' . $oldRelPath . '%')
                    ->orWhere('backdrop_url', 'LIKE', '%' . $oldRelPath . '%')
                    ->get();
                foreach ($items as $mi) {
                    $updates = [];
                    foreach (['file_path', 'poster_url', 'backdrop_url'] as $col) {
                        $orig = $mi->getRawOriginal($col);
                        $next = $replaceInValue($orig);
                        if ($next !== $orig) {
                            $updates[$col] = $next;
                        }
                    }
                    if (!empty($updates)) {
                        DB::table('media_items')->where('id', $mi->id)->update($updates);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('updateDatabasePathsAfterFolderRename media_items error: ' . $e->getMessage());
        }
    }

    /**
     * Agregar una temporada a una serie.
     */
    public function addSeason(Request $request, int $titleId): JsonResponse
    {
        $title = Title::where('type', 'series')->findOrFail($titleId);

        $validated = $request->validate([
            'season_number' => 'required|integer|min:1',
            'title' => 'required|string|max:100',
        ]);

        $season = $title->seasons()->create($validated);

        return response()->json([
            'message' => 'Temporada añadida con éxito.',
            'season' => $season,
        ], 201);
    }

    /**
     * Agregar un episodio a una serie / temporada.
     */
    public function addEpisode(Request $request, int $titleId): JsonResponse
    {
        $title = Title::where('type', 'series')->findOrFail($titleId);

        $validated = $request->validate([
            'season_id' => 'required|exists:seasons,id',
            'episode_number' => 'required|integer|min:1',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'stream_path' => 'required|string',
            'duration_seconds' => 'required|integer|min:1',
            'thumbnail_url' => 'nullable|string',
        ]);

        $validated['title_id'] = $title->id;
        $episode = Episode::create($validated);

        return response()->json([
            'message' => 'Episodio agregado con éxito.',
            'episode' => $episode,
        ], 201);
    }
}
