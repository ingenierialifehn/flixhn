<?php

namespace App\Services;

use App\Jobs\DownloadJob as QueueDownloadJob;
use App\Models\DownloadJob;
use App\Models\MediaItem;
use App\Models\MediaSource;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DownloadService
{
    protected string $daemonUrl;
    protected int $timeout;
    protected int $minFreeSpaceMb;
    protected string $storagePath;
    protected string $hlsStoragePath;

    public function __construct()
    {
        $this->daemonUrl = rtrim((string) config('flixgrab.daemon_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('flixgrab.timeout', 15);
        $this->minFreeSpaceMb = (int) config('flixgrab.min_free_space_mb', 500);
        $this->storagePath = (string) config('flixgrab.storage_path', storage_path('media'));
        $this->hlsStoragePath = (string) config('flixgrab.hls_storage_path', storage_path('media/hls_live'));

        $this->ensureDirectories();
    }

    /**
     * Asegura la existencia física de las rutas de almacenamiento y HLS.
     */
    protected function ensureDirectories(): void
    {
        foreach ([$this->storagePath, $this->hlsStoragePath] as $path) {
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0777, true, true);
            }
        }
    }

    /**
     * Valida el espacio libre disponible en disco antes de admitir una descarga.
     */
    public function checkDiskSpace(): array
    {
        $freeBytes = @disk_free_space($this->storagePath);
        $totalBytes = @disk_total_space($this->storagePath);

        if ($freeBytes === false || $totalBytes === false) {
            return [
                'has_space' => true,
                'free_mb' => 10000,
                'total_mb' => 50000,
                'free_formatted' => 'OK',
            ];
        }

        $freeMb = round($freeBytes / (1024 * 1024), 2);
        $totalMb = round($totalBytes / (1024 * 1024), 2);

        return [
            'has_space' => $freeMb >= $this->minFreeSpaceMb,
            'free_mb' => $freeMb,
            'total_mb' => $totalMb,
            'free_formatted' => $this->formatBytes($freeBytes),
            'total_formatted' => $this->formatBytes($totalBytes),
            'min_required_mb' => $this->minFreeSpaceMb,
        ];
    }

    /**
     * Registra y encola una nueva solicitud de descarga en FlixGrab.
     */
    public function requestDownload(array $data, ?User $user = null): DownloadJob
    {
        $contentId = (string) ($data['content_id'] ?? $data['media_id'] ?? '');
        $titleName = trim($data['title'] ?? $data['name'] ?? '');
        $sourceUrl = trim($data['source_url'] ?? $data['url'] ?? '');

        if (empty($sourceUrl) && !empty($contentId)) {
            $authorizedSources = [
                'big-buck-bunny-2008' => 'https://archive.org/download/BigBuckBunny_124/BigBuckBunny_1080p.mp4',
                'sita-sings-the-blues-2008' => 'https://archive.org/download/Sita_Sings_the_Blues_1080p/Sita_Sings_the_Blues_1080p.mp4',
                'tears-of-steel-2012' => 'https://archive.org/download/Tears-of-Steel/tears_of_steel_1080p.webm',
                'cosmos-laundromat-2015' => 'https://archive.org/download/CosmosLaundromatFirstCycle/Cosmos_Laundromat_First_Cycle_1080p.mp4',
                'night-of-the-living-dead-1968' => 'https://archive.org/download/night_of_the_living_dead/night_of_the_living_dead_1080p.mp4',
            ];
            $sourceUrl = $authorizedSources[$contentId] ?? '';
            if (empty($sourceUrl)) {
                $t = Title::where('id', $contentId)->orWhere('slug', $contentId)->first();
                if ($t && $t->stream_url) {
                    $sourceUrl = $t->stream_url;
                }
            }
        }

        if (empty($sourceUrl)) {
            $provSlug = trim($data['provider'] ?? 'flixgrab');
            $sourceUrl = "https://www." . ($provSlug ?: 'flixgrab') . ".com/media/" . Str::slug($titleName ?: $contentId);
        }

        $titleName = trim($data['title'] ?? $data['name'] ?? '');
        if (empty($titleName)) {
            $titleName = $this->inferTitleFromUrl($sourceUrl);
        }

        $contentId = (string) ($data['content_id'] ?? $data['media_id'] ?? Str::slug($titleName));
        $provider = trim($data['provider'] ?? 'flixgrab');
        $priority = (int) ($data['priority'] ?? 5);
        $quality = (string) ($data['quality'] ?? '1080p');

        // 1. Verificación de espacio en disco
        $disk = $this->checkDiskSpace();
        if (!$disk['has_space']) {
            throw new \RuntimeException("Espacio insuficiente en disco para nuevas descargas ({$disk['free_formatted']} libres, mínimo {$this->minFreeSpaceMb} MB).");
        }

        // 2. Control de duplicados estricto
        $existingJob = DownloadJob::where('content_id', $contentId)
            ->orWhere('source_url', $sourceUrl)
            ->latest()
            ->first();

        if ($existingJob) {
            if ($existingJob->status === 'READY') {
                return $existingJob; // Contenido ya descargado y listo
            }
            if ($existingJob->isActive()) {
                if ($existingJob->status === 'QUEUED') {
                    $this->notifyDaemonNewJob($existingJob);
                }
                return $existingJob;
            }
        }

        // 3. Preparar rutas físicas seguras
        $slug = Str::slug($titleName) ?: ('media_' . time());
        $folderName = Str::slug($provider) ?: 'flixgrab';
        $targetDir = rtrim($this->storagePath, '/\\') . DIRECTORY_SEPARATOR . $folderName;
        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0777, true, true);
        }

        $outputFile = $targetDir . DIRECTORY_SEPARATOR . "{$slug}.mp4";
        $hlsFolder = rtrim($this->hlsStoragePath, '/\\') . DIRECTORY_SEPARATOR . $slug;
        if (!File::isDirectory($hlsFolder)) {
            File::makeDirectory($hlsFolder, 0777, true, true);
        }
        $hlsPlaylistUrl = "/api/streaming/hls/{$slug}/playlist.m3u8";

        // 4. Crear el registro DownloadJob en base de datos
        $jobIdStr = (string) Str::uuid();
        $downloadJob = DownloadJob::create([
            'job_id' => $jobIdStr,
            'content_id' => $contentId,
            'user_id' => $user?->id,
            'title' => $titleName,
            'provider' => $provider,
            'source_url' => $sourceUrl,
            'storage_path' => $outputFile,
            'hls_path' => $hlsFolder,
            'hls_playlist_url' => $hlsPlaylistUrl,
            'status' => 'QUEUED',
            'priority' => $priority,
            'quality' => $quality,
            'metadata' => [
                'poster_url' => $data['poster_url'] ?? null,
                'overview' => $data['overview'] ?? null,
                'year' => $data['year'] ?? null,
                'genre' => $data['genre'] ?? null,
            ],
            'started_at' => now(),
        ]);

        // 5. Notificar al daemon Python FlixGrab
        $dispatchedToDaemon = $this->notifyDaemonNewJob($downloadJob);

        // 6. Si el daemon HTTP no está respondiendo, despachar a la cola Laravel
        if (!$dispatchedToDaemon) {
            try {
                QueueDownloadJob::dispatch($downloadJob->id);
            } catch (\Throwable $e) {
                Log::warning("No fue posible despachar a la cola de Laravel: " . $e->getMessage());
            }
        }

        return $downloadJob->fresh();
    }

    /**
     * Comunica la nueva tarea al microservicio Python FlixGrab.
     */
    protected function notifyDaemonNewJob(DownloadJob $job): bool
    {
        try {
            $response = Http::timeout(3)->post("{$this->getActiveDaemonUrl()}/jobs", [
                'job_id' => $job->job_id,
                'content_id' => $job->content_id,
                'title' => $job->title,
                'source_url' => $job->source_url,
                'storage_path' => $job->storage_path,
                'hls_path' => $job->hls_path,
                'provider' => $job->provider,
                'priority' => $job->priority,
                'quality' => $job->quality,
            ]);

            if ($response->successful()) {
                Log::info("DownloadService: Tarea [{$job->title}] aceptada por FlixGrab Daemon.");
                return true;
            }
        } catch (\Throwable $e) {
            Log::info("DownloadService: FlixGrab Daemon no alcanzable directamente ({$e->getMessage()}), usando ejecutor de respaldo.");
        }

        return false;
    }

    /**
     * Consulta el estado en tiempo real de un trabajo de descarga.
     */
    public function getJobStatus(int|string $id): ?DownloadJob
    {
        $job = is_numeric($id) ? DownloadJob::find($id) : DownloadJob::where('job_id', $id)->first();
        if (!$job) {
            return null;
        }

        // Si el trabajo sigue en QUEUED, asegurar reenvío al daemon si este se reinició
        if ($job->status === 'QUEUED') {
            $this->notifyDaemonNewJob($job);
        }

        // Si el trabajo sigue activo, consultar el estado actualizado del daemon Python
        if ($job->isActive() && $job->job_id) {
            try {
                $response = Http::timeout(2)->get("{$this->getActiveDaemonUrl()}/jobs/{$job->job_id}");
                if ($response->successful()) {
                    $daemonData = $response->json();
                    $jobInfo = $daemonData['job'] ?? $daemonData;
                    if (!empty($jobInfo['status'])) {
                        $job->update([
                            'status' => $jobInfo['status'],
                            'progress' => $jobInfo['progress'] ?? $job->progress,
                            'download_speed' => $jobInfo['download_speed'] ?? $job->download_speed,
                            'eta' => $jobInfo['eta'] ?? $job->eta,
                            'total_bytes' => $jobInfo['total_bytes'] ?? $job->total_bytes,
                            'downloaded_bytes' => $jobInfo['downloaded_bytes'] ?? $job->downloaded_bytes,
                            'segments_ready' => $jobInfo['segments_ready'] ?? $job->segments_ready,
                            'can_play_progressive' => (bool) ($jobInfo['can_play_progressive'] ?? $job->can_play_progressive),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Silenciosamente ignorar falla de polling
            }

            // También verificar disponibilidad física directa de segmentos HLS
            $this->syncHlsProgressiveAvailability($job);
        }

        // Si terminó la descarga y aún no está indexado en Titles, indexarlo
        if ($job->status === 'READY' && !$job->title_id) {
            $this->indexCompletedDownload($job);
        }

        return $job->fresh();
    }

    /**
     * Verifica la disponibilidad de segmentos HLS en disco para reproducción progresiva.
     */
    public function syncHlsProgressiveAvailability(DownloadJob $job): void
    {
        $hlsPath = $job->hls_path;
        if (!File::isDirectory($hlsPath)) {
            $sub = preg_replace('#^.*?storage/media/hls_live/#', '', str_replace('\\', '/', (string)$hlsPath));
            $mapped = rtrim(storage_path('media/hls_live'), '/\\') . '/' . ltrim($sub, '/\\');
            if (File::isDirectory($mapped)) {
                $hlsPath = $mapped;
            } else {
                return;
            }
        }

        $playlistFile = rtrim($hlsPath, '/\\') . DIRECTORY_SEPARATOR . 'playlist.m3u8';
        $tsFiles = File::glob(rtrim($hlsPath, '/\\') . DIRECTORY_SEPARATOR . '*.ts');
        $segmentCount = is_array($tsFiles) ? count($tsFiles) : 0;

        $hasPlaylist = File::exists($playlistFile) && File::size($playlistFile) > 20;
        $canPlay = $hasPlaylist && ($segmentCount >= (int) config('flixgrab.min_segments_for_playback', 1));

        if ($segmentCount !== $job->segments_ready || $canPlay !== $job->can_play_progressive) {
            $job->update([
                'segments_ready' => $segmentCount,
                'can_play_progressive' => $canPlay,
            ]);
        }
    }

    /**
     * Cancela un trabajo de descarga en curso o en cola.
     */
    public function cancelJob(int|string $id): ?DownloadJob
    {
        $job = is_numeric($id) ? DownloadJob::find($id) : DownloadJob::where('job_id', $id)->first();
        if (!$job) {
            return null;
        }

        $job->update([
            'status' => 'CANCELLED',
            'error_message' => 'Descarga cancelada por el usuario.',
            'completed_at' => now(),
        ]);

        // Notificar al daemon para matar el proceso / subproceso
        if ($job->job_id) {
            try {
                Http::timeout(3)->post("{$this->getActiveDaemonUrl()}/jobs/{$job->job_id}/cancel");
            } catch (\Throwable $e) {
                Log::info("No fue posible notificar cancelación a daemon: " . $e->getMessage());
            }
        }

        return $job->fresh();
    }

    /**
     * Determina el estado de disponibilidad unificado de un contenido.
     */
    public function checkContentAvailability(string $contentId): array
    {
        $cleanId = trim($contentId);

        // 1. Verificar si existe título local físico verificado
        $title = Title::where('id', $cleanId)
            ->orWhere('slug', $cleanId)
            ->first();

        if ($title) {
            $rawPath = $title->file_path ?: $title->source_path;
            $hasPhysical = false;
            if (!empty($rawPath)) {
                $hasPhysical = app(\App\Services\MediaIntegrityService::class)->doesPhysicalFileExist($rawPath);
            }
            if ($hasPhysical || $title->provider === 'local') {
                return [
                    'status' => 'LOCAL',
                    'available' => true,
                    'can_play_progressive' => true,
                    'stream_url' => url("/api/media/{$title->id}/play"),
                    'title' => $title->name,
                    'is_local' => true,
                    'progress' => 100,
                ];
            }
        }

        // 2. Verificar si existe un DownloadJob activo o finalizado
        $job = DownloadJob::where('content_id', $cleanId)
            ->orWhere('title', $cleanId)
            ->latest()
            ->first();

        if ($job) {
            if ($job->status === 'QUEUED') {
                $this->notifyDaemonNewJob($job);
            }
            $this->syncHlsProgressiveAvailability($job);

            $streamUrl = null;
            if ($job->status === 'READY') {
                $streamUrl = url("/api/streaming/progressive/{$job->id}");
            } elseif ($job->can_play_progressive) {
                $streamUrl = url($job->hls_playlist_url ?: "/api/streaming/hls/{$job->job_id}/playlist.m3u8");
            }

            return [
                'status' => $job->status,
                'job_id' => $job->job_id,
                'download_job_id' => $job->id,
                'available' => $job->is_playable,
                'can_play_progressive' => (bool) $job->can_play_progressive,
                'segments_ready' => $job->segments_ready,
                'progress' => (float) $job->progress,
                'download_speed' => $job->download_speed,
                'eta' => $job->eta,
                'stream_url' => $streamUrl,
                'is_local' => $job->status === 'READY',
                'title' => $job->title,
                'error_message' => $job->error_message,
            ];
        }

        // 3. Si no existe localmente ni hay descarga, es contenido REMOTO disponible para solicitud
        $authorizedSources = [
            'big-buck-bunny-2008' => 'https://archive.org/download/BigBuckBunny_124/BigBuckBunny_1080p.mp4',
            'sita-sings-the-blues-2008' => 'https://archive.org/download/Sita_Sings_the_Blues_1080p/Sita_Sings_the_Blues_1080p.mp4',
            'tears-of-steel-2012' => 'https://archive.org/download/Tears-of-Steel/tears_of_steel_1080p.webm',
            'cosmos-laundromat-2015' => 'https://archive.org/download/CosmosLaundromatFirstCycle/Cosmos_Laundromat_First_Cycle_1080p.mp4',
            'night-of-the-living-dead-1968' => 'https://archive.org/download/night_of_the_living_dead/night_of_the_living_dead_1080p.mp4',
        ];
        $knownSourceUrl = $authorizedSources[$cleanId] ?? ($title?->stream_url ?: null);
        $provider = $title?->provider ?? 'remote';
        $isOtt = in_array(strtolower($provider), ['netflix', 'disney', 'max', 'prime', 'amazon', 'apple', 'paramount', 'starplus', 'crunchyroll']);

        return [
            'status' => 'REMOTE',
            'available' => false,
            'can_play_progressive' => false,
            'stream_url' => null,
            'source_url' => $knownSourceUrl,
            'is_local' => false,
            'is_ott' => $isOtt,
            'provider' => $provider,
            'title' => $title?->name ?? $cleanId,
            'progress' => 0,
        ];
    }

    /**
     * Indexa automáticamente un contenido descargado con éxito en el catálogo FlixHN.
     */
    public function indexCompletedDownload(DownloadJob $job): ?Title
    {
        if (!File::exists($job->storage_path)) {
            return null;
        }

        $titleSlug = Str::slug($job->title);
        $title = Title::firstOrCreate(
            ['slug' => $titleSlug],
            [
                'name' => $job->title,
                'type' => 'movie',
                'description' => $job->metadata['overview'] ?? 'Película descargada e indexada por FlixGrab.',
                'poster_url' => $job->metadata['poster_url'] ?? '/img/placeholder-poster.png',
                'release_year' => $job->metadata['year'] ?? (int) date('Y'),
                'genre' => $job->metadata['genre'] ?? 'Acción',
                'file_path' => $job->storage_path,
                'stream_path' => $job->storage_path,
                'source_type' => 'local',
                'provider' => $job->provider ?: 'flixgrab',
                'duration_seconds' => $job->duration_seconds ?: 5400,
            ]
        );

        $job->update([
            'title_id' => $title->id,
        ]);

        return $title;
    }

    /**
     * Procesa la descarga directamente en PHP/CLI en caso de fallback sin daemon externo.
     */
    public function processJob(DownloadJob $job): void
    {
        $job->update([
            'status' => 'DOWNLOADING',
            'started_at' => now(),
            'progress' => 5,
            'download_speed' => 'Iniciando...',
        ]);

        try {
            $destFile = $job->storage_path;
            $sourceUrl = $job->source_url;

            // Stream download con soporte de chunks y cálculo de progreso
            $tempFile = $destFile . '.part';
            $fp = fopen($tempFile, 'wb');
            if (!$fp) {
                throw new \RuntimeException("No es posible escribir en {$tempFile}");
            }

            $response = Http::withOptions([
                'sink' => $fp,
                'timeout' => 3600,
            ])->get($sourceUrl);

            fclose($fp);

            if (!$response->successful() && !File::exists($tempFile)) {
                throw new \RuntimeException("Falla al descargar desde la fuente: HTTP " . $response->status());
            }

            // Renombrar a archivo final
            if (File::exists($destFile)) {
                File::delete($destFile);
            }
            rename($tempFile, $destFile);

            $fileSize = File::size($destFile);

            $job->update([
                'status' => 'READY',
                'progress' => 100.0,
                'total_bytes' => $fileSize,
                'downloaded_bytes' => $fileSize,
                'download_speed' => 'Completado',
                'eta' => '00:00',
                'can_play_progressive' => true,
                'completed_at' => now(),
            ]);

            $this->indexCompletedDownload($job);
            Log::info("DownloadService: Descarga finalizada e indexada con éxito para [{$job->title}] ({$fileSize} bytes).");
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'ERROR',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            Log::error("DownloadService: Falla procesando descarga [{$job->title}]: " . $e->getMessage());
        }
    }

    protected function inferTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = basename($path ?: 'video');
        $name = pathinfo($base, PATHINFO_FILENAME);
        $clean = preg_replace('/[._-]+/', ' ', $name);
        return ucwords(trim($clean ?: 'Nuevo Contenido'));
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0.0 MB';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Resuelve dinámicamente la URL activa del daemon FlixGrab.
     * Compatible tanto con host local nativo como con contenedores Docker (host.docker.internal / bridge gateway).
     */
    protected static ?string $resolvedDaemonUrl = null;

    public function getActiveDaemonUrl(): string
    {
        if (self::$resolvedDaemonUrl !== null) {
            return self::$resolvedDaemonUrl;
        }

        $urls = array_unique(array_filter([
            env('FLIXGRAB_DAEMON_URL'),
            'http://host.docker.internal:8001',
            'http://172.17.0.1:8001',
            $this->daemonUrl,
            'http://127.0.0.1:8001',
        ]));

        foreach ($urls as $url) {
            try {
                $check = Http::timeout(1)->get("{$url}/health");
                if ($check->successful()) {
                    self::$resolvedDaemonUrl = $url;
                    return $url;
                }
            } catch (\Throwable) {
                // Siguiente candidato
            }
        }

        return $this->daemonUrl;
    }
}
