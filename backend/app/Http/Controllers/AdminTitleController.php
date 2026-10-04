<?php

namespace App\Http\Controllers;

use App\Models\Title;
use App\Models\User;
use App\Models\Season;
use App\Models\Episode;
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

        // 3. Alertas Reales: Array vacío [] mientras no existan eventos reales en el sistema
        $alerts = [];

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
                'is_on_net' => true,
                'on_net_status' => 'En línea',
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
     * Listar todos los títulos del catálogo con relaciones.
     */
    public function index(): JsonResponse
    {
        $titles = Title::with(['seasons.episodes'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'titles' => $titles,
        ]);
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
