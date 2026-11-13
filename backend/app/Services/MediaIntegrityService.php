<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Models\TvChannel;
use App\Models\MediaItem;
use App\Models\PlaybackProgress;
use App\Models\MediaIngestTask;
use App\Models\OttDownloadTask;
use App\Models\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MediaIntegrityService
{
    /**
     * Rutas predeterminadas de bibliotecas locales en el servidor (Disk2 a Disk7 y almacenamiento On-Net).
     */
    protected array $standardDisks = [
        '/mnt/media_library/Disk2',
        '/mnt/media_library/Disk3',
        '/mnt/media_library/Disk4',
        '/mnt/media_library/Disk5',
        '/mnt/media_library/Disk6',
        '/mnt/media_library/Disk7',
    ];

    /**
     * Verifica si una ruta de archivo local existe físicamente en el disco.
     * Evalúa tanto la ruta exacta como las ubicaciones estándar montadas en el servidor.
     */
    public function doesPhysicalFileExist(?string $filePath): bool
    {
        if (empty($filePath)) {
            return false;
        }

        // 1. Verificación directa en el sistema de archivos
        if (file_exists($filePath) && !is_dir($filePath) && @filesize($filePath) > 0) {
            return true;
        }

        // 2. Normalización de barras para compatibilidad Linux/Windows
        $normalized = str_replace('\\', '/', $filePath);
        if (file_exists($normalized) && !is_dir($normalized) && @filesize($normalized) > 0) {
            return true;
        }

        // 3. Resolución en puntos de montaje Disk2–Disk7 y directorios de almacenamiento
        $fileName = basename($normalized);
        $candidates = [
            $normalized,
            "/mnt/media_library/Disk2/{$fileName}",
            "/mnt/media_library/Disk3/{$fileName}",
            "/mnt/media_library/Disk4/{$fileName}",
            "/mnt/media_library/Disk5/{$fileName}",
            "/mnt/media_library/Disk6/{$fileName}",
            "/mnt/media_library/Disk7/{$fileName}",
            storage_path(ltrim($normalized, '/')),
            storage_path('media/' . $fileName),
            storage_path('media/movies/' . $fileName),
            storage_path('media/series/' . $fileName),
            "/var/www/html/storage/media/{$fileName}",
            "/var/www/html/storage/media/movies/{$fileName}",
            "/var/www/html/storage/media/series/{$fileName}",
            "/var/media/{$fileName}",
            base_path("media/{$fileName}"),
        ];

        // Directorios si incluye subcarpeta de categoría
        $parentDir = basename(dirname($normalized));
        if (!empty($parentDir) && $parentDir !== '.' && $parentDir !== '/') {
            foreach ($this->standardDisks as $disk) {
                $candidates[] = "{$disk}/{$parentDir}/{$fileName}";
            }
            $candidates[] = storage_path("media/{$parentDir}/{$fileName}");
            $candidates[] = "/var/www/html/storage/media/{$parentDir}/{$fileName}";
        }

        foreach ($candidates as $cand) {
            if (!empty($cand) && file_exists($cand) && !is_dir($cand) && @filesize($cand) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprueba si un registro de título es de tipo local.
     */
    public function isLocalTitle(Title $title): bool
    {
        $provider = $title->provider ?? 'local';
        $filePath = $title->source_path ?? $title->stream_path ?? null;

        if ($provider === 'local') {
            return true;
        }

        if (!empty($filePath)) {
            if (str_starts_with($filePath, '/mnt/') ||
                str_starts_with($filePath, '/var/') ||
                str_starts_with($filePath, 'storage/') ||
                str_starts_with($filePath, '/storage/') ||
                preg_match('/^[a-zA-Z]:[\/\\\\]/', $filePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprueba si un registro de episodio es de tipo local.
     */
    public function isLocalEpisode(Episode $episode): bool
    {
        $sourceType = $episode->source_type ?? 'local';
        $filePath = $episode->file_path ?? $episode->stream_path ?? null;

        if ($sourceType === 'local') {
            return true;
        }

        if (!empty($filePath)) {
            if (str_starts_with($filePath, '/mnt/') ||
                str_starts_with($filePath, '/var/') ||
                str_starts_with($filePath, 'storage/') ||
                str_starts_with($filePath, '/storage/') ||
                preg_match('/^[a-zA-Z]:[\/\\\\]/', $filePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica la integridad de un Title (película o serie).
     * Si el archivo físico ya no existe, purga automáticamente de la BD y devuelve false.
     * Si es válido o remoto, devuelve true.
     */
    public function verifyOrPurgeTitle(Title $title): bool
    {
        if ($title->type === 'movie') {
            if ($this->isLocalTitle($title)) {
                $filePath = $title->source_path ?: $title->stream_path;
                if (!$this->doesPhysicalFileExist($filePath)) {
                    Log::warning("MediaIntegrityService: Película física eliminada del disco [ID {$title->id}: {$title->name}]. Purgando de la base de datos.");
                    $this->purgeTitle($title);
                    return false;
                }
            }
            return true;
        }

        // Si es una serie: verificar episodios
        if ($title->type === 'series') {
            if ($this->isLocalTitle($title)) {
                $episodes = Episode::where('title_id', $title->id)->get();
                $validEpisodesCount = 0;

                foreach ($episodes as $ep) {
                    $epPath = $ep->file_path ?: $ep->stream_path;
                    if ($this->isLocalEpisode($ep)) {
                        if (!$this->doesPhysicalFileExist($epPath)) {
                            Log::warning("MediaIntegrityService: Episodio físico eliminado del disco [ID {$ep->id}: {$ep->title}]. Purgando episodio.");
                            $this->purgeEpisode($ep, false);
                            continue;
                        }
                    }
                    $validEpisodesCount++;
                }

                // Si la serie se quedó sin episodios válidos, eliminar la serie completa
                if ($validEpisodesCount === 0) {
                    Log::warning("MediaIntegrityService: Serie huérfana sin episodios válidos en disco [ID {$title->id}: {$title->name}]. Purgando serie.");
                    $this->purgeTitle($title);
                    return false;
                }
            }
            return true;
        }

        return true;
    }

    /**
     * Verifica la integridad de un Episode.
     * Si el archivo físico ya no existe, purga el episodio y la serie padre si queda huérfana.
     */
    public function verifyOrPurgeEpisode(Episode $episode): bool
    {
        if ($this->isLocalEpisode($episode)) {
            $epPath = $episode->file_path ?: $episode->stream_path;
            if (!$this->doesPhysicalFileExist($epPath)) {
                Log::warning("MediaIntegrityService: Archivo de episodio no encontrado [ID {$episode->id}: {$episode->title}]. Purgando.");
                $this->purgeEpisode($episode, true);
                return false;
            }
        }
        return true;
    }

    /**
     * Purga un Título (película o serie) de MySQL en cascada.
     */
    public function purgeTitle(Title $title): void
    {
        $titleId = $title->id;
        $titleName = $title->name;

        // Eliminar registros asociados en cascada
        $this->purgeAssociatedData('title', $titleId, $titleName);

        // Si es serie, eliminar temporadas y episodios
        if ($title->type === 'series') {
            $episodes = Episode::where('title_id', $titleId)->get();
            foreach ($episodes as $ep) {
                $this->purgeAssociatedData('episode', $ep->id, $ep->title);
                $ep->delete();
            }
            Season::where('title_id', $titleId)->delete();
        }

        // Eliminar título principal
        $title->delete();

        // Limpiar caché de catálogo al instante
        $this->clearCatalogCache();
    }

    /**
     * Purga un Episodio de MySQL y evalúa si la serie padre se queda sin episodios válidos.
     */
    public function purgeEpisode(Episode $episode, bool $checkParentSeries = true): void
    {
        $episodeId = $episode->id;
        $titleId = $episode->title_id;
        $epTitle = $episode->title;

        $this->purgeAssociatedData('episode', $episodeId, $epTitle);
        $episode->delete();

        if ($checkParentSeries && $titleId) {
            $remainingCount = Episode::where('title_id', $titleId)->count();
            if ($remainingCount === 0) {
                $parent = Title::find($titleId);
                if ($parent && $parent->type === 'series') {
                    Log::info("MediaIntegrityService: Serie padre [ID {$titleId}] se quedó con 0 episodios. Purgando serie padre.");
                    $this->purgeTitle($parent);
                }
            }
        }

        $this->clearCatalogCache();
    }

    /**
     * Purga un canal de televisión.
     */
    public function purgeChannel(TvChannel $channel): void
    {
        $channelName = $channel->name;

        if (Schema::hasTable('device_sessions')) {
            try {
                if (!empty($channelName)) {
                    DeviceSession::where('current_media_title', $channelName)->delete();
                }
            } catch (\Throwable $e) {
                Log::debug("MediaIntegrityService::purgeChannel: Error limpiando device_sessions: " . $e->getMessage());
            }
        }

        $channel->delete();

        Cache::forget('livetv_channels_list');
        Cache::forget('emby_livetv_channels_map');
        $this->clearCatalogCache();
    }

    /**
     * Elimina en cascada todos los registros en tablas dependientes
     * (watch_history, playback_progress, favorites, my_list, downloads, device_sessions).
     */
    public function purgeAssociatedData(string $type, int $id, ?string $titleName = null): void
    {
        $idColumn = $type === 'episode' ? 'episode_id' : 'title_id';

        // 1. playback_progress / watch_history
        if (Schema::hasTable('playback_progress')) {
            try {
                DB::table('playback_progress')->where($idColumn, $id)->delete();
            } catch (\Throwable $e) {
                Log::debug("MediaIntegrityService: Error limpiando playback_progress: " . $e->getMessage());
            }
        }

        if (Schema::hasTable('watch_history')) {
            try {
                DB::table('watch_history')->where($idColumn, $id)->delete();
            } catch (\Throwable $e) {}
        }

        // 2. favorites / my_list
        if (Schema::hasTable('favorites')) {
            try {
                DB::table('favorites')->where($idColumn, $id)->delete();
            } catch (\Throwable $e) {}
        }

        if (Schema::hasTable('my_list')) {
            try {
                DB::table('my_list')->where($idColumn, $id)->delete();
            } catch (\Throwable $e) {}
        }

        // 3. Tareas de descargas (MediaIngestTask, OttDownloadTask, downloads)
        if ($type === 'title') {
            if (Schema::hasTable('media_items')) {
                try {
                    MediaItem::where('title_id', $id)->delete();
                } catch (\Throwable $e) {}
            }

            if (Schema::hasTable('ott_download_tasks')) {
                try {
                    OttDownloadTask::where('media_item_id', $id)->delete();
                } catch (\Throwable $e) {}
            }

            if (Schema::hasTable('downloads')) {
                try {
                    DB::table('downloads')->where('title_id', $id)->delete();
                } catch (\Throwable $e) {}
            }
        }

        // 4. device_sessions (telemetría de sesiones activas: filtrado por current_media_title)
        if (Schema::hasTable('device_sessions')) {
            try {
                if (!empty($titleName)) {
                    DeviceSession::where('current_media_title', $titleName)->delete();
                }
            } catch (\Throwable $e) {
                Log::debug("MediaIntegrityService: Error limpiando device_sessions: " . $e->getMessage());
            }
        }
    }

    /**
     * Invalida de forma inmediata todas las llaves de caché de catálogo.
     */
    public function clearCatalogCache(): void
    {
        Cache::forget('catalog_home_base');
        Cache::forget('catalog_home_kids');
        Cache::forget('livetv_channels_list');
        Cache::forget('emby_livetv_channels_map');

        // Disparar timestamp de actualización de catálogo para sincronización multi-proceso
        Cache::put('catalog_last_updated_at', now()->timestamp, 86400);
    }

    /**
     * Respuesta HTTP 410 Gone estandarizada para contenido eliminado.
     */
    public function goneResponse(?int $id = null, string $type = 'media', ?string $message = null): JsonResponse
    {
        return response()->json([
            'deleted' => true,
            'id' => $id,
            'type' => $type,
            'message' => $message ?: 'Contenido eliminado o no disponible.',
        ], 410);
    }
}

