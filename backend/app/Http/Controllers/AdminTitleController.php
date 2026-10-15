<?php

namespace App\Http\Controllers;

use App\Models\Title;
use App\Models\User;
use App\Models\Season;
use App\Models\Episode;
use App\Models\Category;
use App\Models\PlaybackProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminTitleController extends Controller
{
    /**
     * Métricas reales y dinámicas del Dashboard de Administración (Telemetría ISP).
     */
    public function dashboardStats(): JsonResponse
    {
        $titlesCount = Title::count();
        $totalMovies = Title::where('type', 'movie')->count();
        $totalSeries = Title::where('type', 'series')->count();
        $subscribersCount = User::where('role', 'subscriber')->count();
        $activeSubscribers = User::where('role', 'subscriber')->where('is_active', true)->count();

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
     * Sincronizar catálogo automáticamente desde el servidor de medios remoto por IP y API Key.
     * POST /api/admin/media/sync
     */
    public function syncRemoteMedia(Request $request, \App\Services\RemoteMediaConnectorService $remoteService): JsonResponse
    {
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
        $serverUrl = $request->input('server_url');
        $apiKey = $request->input('api_key');

        // Ejecutar extracción con el conector remoto
        $response = $remoteService->syncCatalog($serverUrl, $apiKey);

        if ($response->getStatusCode() === 200) {
            return $response;
        }

        // Fallback a escaneo local si no hay conexión remota
        $localResult = $syncService->sync($serverUrl, $apiKey);
        return response()->json(array_merge($localResult, [
            'success' => true,
            'total_synced' => $localResult['metrics']['total_titles'] ?? 0,
        ]));
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
        // 1. Resolver y sanitizar directorio base multimedia
        $baseMediaDir = env('MEDIA_PATH');
        if (!$baseMediaDir || !is_dir($baseMediaDir)) {
            $baseMediaDir = is_dir('/var/media') ? '/var/media' : base_path('../media');
        }
        if (!is_dir($baseMediaDir)) {
            $baseMediaDir = base_path('media');
        }
        if (!is_dir($baseMediaDir)) {
            @mkdir($baseMediaDir, 0777, true);
        }
        $realBase = realpath($baseMediaDir) ?: $baseMediaDir;

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
            if ($realCurrent !== false && !str_starts_with($realCurrent, $realBase)) {
                return response()->json([
                    'message' => 'Acceso denegado: la ruta solicitada está fuera del almacenamiento multimedia.',
                ], 403);
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
                'relative_path' => 'movies',
                'path' => 'movies',
                'items_count' => $movieCount,
                'type' => 'library',
            ];
            $folders[] = [
                'name' => 'Series',
                'relative_path' => 'series',
                'path' => 'series',
                'items_count' => $seriesCount,
                'type' => 'library',
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
                            'relative_path' => $entry,
                            'path' => $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
                        ];
                    } elseif (is_file($fullEntry)) {
                        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                        if (in_array($ext, $videoExtensions)) {
                            $titles->push($this->wrapPhysicalFileAsTitle($fullEntry, $entry, $baseMediaDir));
                        }
                    }
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
                        $folders[] = [
                            'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'relative_path' => 'movies/' . $entry,
                            'path' => 'movies/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
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
                        'relative_path' => $subRel,
                        'path' => $subRel,
                        'items_count' => $titleCount,
                        'type' => 'category',
                    ];
                }
            }

            // Películas no categorizadas o con stream_path directo
            $unassignedMovies = Title::with(['seasons:id,title_id,season_number'])
                ->where('type', 'movie')
                ->where(function ($q) {
                    $q->whereNull('category_id')->orWhere('stream_path', 'LIKE', 'movies/%');
                })
                ->whereNotIn('id', $titles->pluck('id')->filter()->toArray())
                ->take(50)
                ->get();
            $titles = $titles->concat($unassignedMovies);

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
                        $folders[] = [
                            'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                            'relative_path' => 'series/' . $entry,
                            'path' => 'series/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
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
                        'relative_path' => $subRel,
                        'path' => $subRel,
                        'items_count' => $titleCount,
                        'type' => 'category',
                    ];
                }
            }

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
                            'relative_path' => $cleanPath . '/' . $entry,
                            'path' => $cleanPath . '/' . $entry,
                            'items_count' => $countPhysicalItems($fullEntry),
                            'type' => 'folder',
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

        // Buscar si ya existe en la base de datos por stream_path, source_path o nombre
        $existing = Title::with(['seasons:id,title_id,season_number'])
            ->where('stream_path', 'LIKE', '%' . $relativePath . '%')
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
     * Actualizar título existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $title = Title::findOrFail($id);

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

        return response()->json([
            'message' => 'Título actualizado exitosamente.',
            'title' => $title->fresh(['seasons.episodes']),
        ]);
    }

    /**
     * Eliminar título del catálogo.
     */
    public function destroy(int $id): JsonResponse
    {
        $title = Title::findOrFail($id);
        $title->delete();

        return response()->json([
            'message' => 'Título eliminado del catálogo.',
        ]);
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
