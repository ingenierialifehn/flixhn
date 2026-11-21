<?php

namespace App\Services;

use App\Jobs\ProcessMediaIngestionJob;
use App\Models\Category;
use App\Models\Episode;
use App\Models\MediaIngestionTask;
use App\Models\MediaItem;
use App\Models\Plugin;
use App\Models\Season;
use App\Models\ServerSetting;
use App\Models\Title;
use App\Services\Ott\Security\SsrfValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MediaIngestionService
{
    /**
     * Discos locales estándar montados en el servidor FlixHN.
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
     * Garantiza que la tabla media_ingestion_tasks exista incluso si aún no se ejecutó migrate.
     */
    public function ensureSchema(): void
    {
        if (Schema::hasTable('media_ingestion_tasks')) {
            return;
        }

        Schema::create('media_ingestion_tasks', function (Blueprint $table) {
            $table->id();
            $table->enum('content_type', ['movie', 'series'])->default('movie');
            $table->string('title', 255);
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('imdb_id', 32)->nullable();
            $table->string('tmdb_id', 32)->nullable();
            $table->text('overview')->nullable();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->string('episode_title', 255)->nullable();
            $table->text('source_url');
            $table->string('source_type', 40)->default('direct_http');
            $table->string('target_disk', 255);
            $table->string('target_folder', 500);
            $table->string('output_file', 500)->nullable();
            $table->string('quality', 30)->default('1080p');
            $table->enum('status', ['pending', 'downloading', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('download_speed', 50)->nullable();
            $table->string('eta', 50)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->integer('pid')->nullable();
            $table->text('error_log')->nullable();
            $table->longText('logs')->nullable();
            $table->unsignedBigInteger('title_id')->nullable();
            $table->unsignedBigInteger('episode_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('content_type');
            $table->index('created_at');
        });
    }

    /**
     * Devuelve el estado en tiempo real de los discos /mnt/media_library/Disk2..Disk7.
     */
    public function getAvailableDisks(): array
    {
        $configured = [];
        try {
            $scanner = app(LocalMediaScannerService::class);
            $configured = $scanner->getConfiguredPaths();
        } catch (\Throwable $e) {
            $configured = [];
        }

        $candidatePaths = array_values(array_unique(array_merge($this->defaultDisks, $configured)));
        $disks = [];
        $anyMountedWritable = false;

        foreach ($candidatePaths as $rawPath) {
            $path = rtrim(str_replace('\\', '/', trim((string) $rawPath)), '/');
            if ($path === '') {
                continue;
            }

            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            if ($writable) {
                $anyMountedWritable = true;
            }

            $totalBytes = $exists ? (int) (@disk_total_space($path) ?: 0) : 0;
            $freeBytes = $exists ? (int) (@disk_free_space($path) ?: 0) : 0;
            $usedBytes = max(0, $totalBytes - $freeBytes);
            $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0;

            $disks[] = [
                'path' => $path,
                'name' => basename($path) ?: $path,
                'mounted' => $exists,
                'writable' => $writable,
                'total_bytes' => $totalBytes,
                'free_bytes' => $freeBytes,
                'used_bytes' => $usedBytes,
                'used_percent' => $usedPercent,
                'total_formatted' => $this->formatBytes($totalBytes),
                'free_formatted' => $this->formatBytes($freeBytes),
                'is_fallback' => false,
            ];
        }

        // En desarrollo local (ej. Windows sin /mnt/media_library montado), ofrecer storage/media como respaldo escribible
        if (!$anyMountedWritable) {
            $devPath = rtrim(str_replace('\\', '/', storage_path('media')), '/');
            if (!is_dir($devPath)) {
                @mkdir($devPath, 0775, true);
            }
            $exists = is_dir($devPath);
            $writable = $exists && is_writable($devPath);
            $totalBytes = $exists ? (int) (@disk_total_space($devPath) ?: 0) : 0;
            $freeBytes = $exists ? (int) (@disk_free_space($devPath) ?: 0) : 0;
            $usedBytes = max(0, $totalBytes - $freeBytes);
            $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0;

            $disks[] = [
                'path' => $devPath,
                'name' => 'Local Dev (storage/media)',
                'mounted' => $exists,
                'writable' => $writable,
                'total_bytes' => $totalBytes,
                'free_bytes' => $freeBytes,
                'used_bytes' => $usedBytes,
                'used_percent' => $usedPercent,
                'total_formatted' => $this->formatBytes($totalBytes),
                'free_formatted' => $this->formatBytes($freeBytes),
                'is_fallback' => true,
            ];
        }

        return $disks;
    }

    /**
     * Comprueba los binarios disponibles dentro del contenedor/servidor.
     */
    public function getAvailableTools(): array
    {
        $tools = ['ffmpeg', 'ffprobe', 'yt-dlp', 'aria2c', 'curl', 'python3'];
        $status = [];
        foreach ($tools as $bin) {
            $status[$bin] = $this->findBinary($bin) !== null;
        }
        return $status;
    }

    /**
     * Valida la URL de origen, aplica protección SSRF y detecta el tipo de flujo multimedia.
     *
     * @return array{valid: bool, url: string, source_type: string, reason: ?string}
     */
    public function validateSourceUrl(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '' || !filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'url' => $trimmed,
                'source_type' => 'unknown',
                'reason' => 'La URL proporcionada no tiene un formato HTTP/HTTPS válido.',
            ];
        }

        $parts = parse_url($trimmed);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = strtolower($parts['path'] ?? '');
        $query = strtolower($parts['query'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            return [
                'valid' => false,
                'url' => $trimmed,
                'source_type' => 'unknown',
                'reason' => 'Solo se admiten enlaces con protocolo HTTP o HTTPS.',
            ];
        }

        // Permitir explícitamente el host configurado del servidor de medios interno / OTT del ISP
        $trustedInternal = $this->isConfiguredInternalHost($host);
        if (!$trustedInternal) {
            $ssrf = SsrfValidator::validate($trimmed, true);
            if (!($ssrf['safe'] ?? false)) {
                return [
                    'valid' => false,
                    'url' => $trimmed,
                    'source_type' => 'unknown',
                    'reason' => 'URL bloqueada por validación de seguridad SSRF: ' . ($ssrf['reason'] ?? 'host no permitido'),
                ];
            }
        }

        // Clasificar el tipo de fuente
        if (str_contains($path, '.m3u8') || str_contains($query, '.m3u8') || str_contains($path, '/hls/')) {
            $sourceType = 'hls_m3u8';
        } elseif ($trustedInternal || str_contains($path, '/emby/') || str_contains($path, '/api/ott') || str_contains($path, '/api/stream')) {
            $sourceType = 'ott_feed';
        } elseif (preg_match('/\.(mp4|mkv|m4v|mov|webm|ts|avi)$/i', $path)) {
            $sourceType = 'direct_http';
        } else {
            $sourceType = 'web_standard';
        }

        return [
            'valid' => true,
            'url' => $trimmed,
            'source_type' => $sourceType,
            'reason' => null,
        ];
    }

    /**
     * Encola una nueva tarea de ingesta en segundo plano.
     */
    public function enqueue(array $payload, ?int $userId = null): MediaIngestionTask
    {
        $this->ensureSchema();

        $sourceUrl = trim((string) ($payload['source_url'] ?? ''));
        $validation = $this->validateSourceUrl($sourceUrl);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException($validation['reason'] ?? 'URL de origen no válida.');
        }

        $contentType = strtolower(trim((string) ($payload['content_type'] ?? 'movie')));
        if (!in_array($contentType, ['movie', 'series'], true)) {
            $contentType = 'movie';
        }

        $rawTitle = trim((string) ($payload['title'] ?? ''));
        if ($rawTitle === '') {
            throw new \InvalidArgumentException('El título del contenido es obligatorio.');
        }

        // Autodetección de temporada y episodio (S01E02 / 1x02) si es serie
        $seasonNumber = isset($payload['season_number']) && $payload['season_number'] !== '' ? (int) $payload['season_number'] : null;
        $episodeNumber = isset($payload['episode_number']) && $payload['episode_number'] !== '' ? (int) $payload['episode_number'] : null;
        $episodeTitle = !empty($payload['episode_title']) ? trim((string) $payload['episode_title']) : null;

        if ($contentType === 'series') {
            if ($seasonNumber === null || $episodeNumber === null) {
                $detected = $this->detectSeasonEpisode($rawTitle . ' ' . $sourceUrl);
                $seasonNumber = $seasonNumber ?? $detected['season'] ?? 1;
                $episodeNumber = $episodeNumber ?? $detected['episode'] ?? 1;
            }
            $rawTitle = trim((string) preg_replace('/\bS\d{1,2}E\d{1,3}\b|\b\d{1,2}x\d{1,3}\b/i', '', $rawTitle)) ?: $rawTitle;
        }

        $releaseYear = !empty($payload['release_year']) ? (int) $payload['release_year'] : null;
        if ($releaseYear === null && preg_match('/\b(19\d{2}|20\d{2})\b/', $rawTitle, $ym)) {
            $releaseYear = (int) $ym[1];
        }

        $imdbId = !empty($payload['imdb_id']) ? strtolower(trim((string) $payload['imdb_id'])) : null;
        $tmdbId = !empty($payload['tmdb_id']) ? trim((string) $payload['tmdb_id']) : null;
        $overview = !empty($payload['overview']) ? trim((string) $payload['overview']) : null;
        $quality = !empty($payload['quality']) ? trim((string) $payload['quality']) : '1080p';

        // Validar disco de destino y construir rutas limpias
        $targetDisk = $this->validateAndResolveDisk((string) ($payload['target_disk'] ?? ''));
        $subfolder = isset($payload['target_subfolder']) ? trim((string) $payload['target_subfolder']) : '';
        [$targetFolder, $outputFile] = $this->buildDestinationPaths(
            $targetDisk,
            $contentType,
            $rawTitle,
            $releaseYear,
            $seasonNumber,
            $episodeNumber,
            $subfolder
        );

        // Garantizar permisos de escritura en la ruta destino antes de encolar
        $this->ensureWritableDirectory($targetFolder);

        $task = new MediaIngestionTask([
            'content_type' => $contentType,
            'title' => $rawTitle,
            'release_year' => $releaseYear,
            'imdb_id' => $imdbId,
            'tmdb_id' => $tmdbId,
            'overview' => $overview,
            'season_number' => $contentType === 'series' ? $seasonNumber : null,
            'episode_number' => $contentType === 'series' ? $episodeNumber : null,
            'episode_title' => $contentType === 'series' ? $episodeTitle : null,
            'source_url' => $validation['url'],
            'source_type' => $validation['source_type'],
            'target_disk' => $targetDisk,
            'target_folder' => $targetFolder,
            'output_file' => $outputFile,
            'quality' => $quality,
            'status' => 'pending',
            'progress' => 0,
            'download_speed' => '0.0 MB/s',
            'eta' => 'En cola...',
            'file_size' => 0,
            'created_by' => $userId,
        ]);

        $task->appendLog("Tarea encolada ({$contentType}) • Origen [{$validation['source_type']}]: {$validation['url']}");
        $task->appendLog("Destino configurado: {$outputFile}");
        $task->save();

        // Despachar a la cola Laravel (si hay worker activo) + lanzar proceso CLI desacoplado para ejecución inmediata sin bloquear PHP-FPM
        $queueDriver = config('queue.default', 'sync');
        if ($queueDriver !== 'sync') {
            try {
                ProcessMediaIngestionJob::dispatch($task->id);
            } catch (\Throwable $e) {
                Log::warning("MediaIngestionService::enqueue: No se pudo despachar a queue '{$queueDriver}': " . $e->getMessage());
            }
        }

        $this->launchDetachedRunner($task->id);

        return $task->fresh();
    }

    /**
     * Devuelve el resumen de tareas, estado de discos y herramientas para el panel administrativo.
     */
    public function getStatusOverview(?string $status = null, ?string $search = null): array
    {
        $this->ensureSchema();

        $query = MediaIngestionTask::with(['titleModel:id,name,type,poster_url', 'episodeModel:id,episode_number,title'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($status) && $status !== 'all') {
            if ($status === 'active') {
                $query->whereIn('status', ['pending', 'downloading', 'processing']);
            } else {
                $query->where('status', $status);
            }
        }

        if (!empty($search)) {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('source_url', 'LIKE', "%{$term}%")
                  ->orWhere('imdb_id', 'LIKE', "%{$term}%")
                  ->orWhere('output_file', 'LIKE', "%{$term}%");
            });
        }

        $tasks = $query->limit(150)->get();

        $summary = [
            'active' => MediaIngestionTask::whereIn('status', ['pending', 'downloading', 'processing'])->count(),
            'completed' => MediaIngestionTask::where('status', 'completed')->count(),
            'failed' => MediaIngestionTask::whereIn('status', ['failed', 'cancelled'])->count(),
            'total' => MediaIngestionTask::count(),
        ];

        return [
            'tasks' => $tasks,
            'summary' => $summary,
            'disks' => $this->getAvailableDisks(),
            'tools' => $this->getAvailableTools(),
        ];
    }

    /**
     * Cancela una tarea activa (deteniendo su proceso) o elimina del historial una tarea finalizada.
     */
    public function cancelOrDelete(int $taskId): array
    {
        $this->ensureSchema();
        $task = MediaIngestionTask::findOrFail($taskId);

        if ($task->isActive()) {
            $pid = $task->pid;
            if ($pid && $pid > 0) {
                $this->terminatePid($pid);
            }

            $task->status = 'cancelled';
            $task->download_speed = '0.0 MB/s';
            $task->eta = 'Cancelado';
            $task->error_log = 'Tarea cancelada manualmente por el administrador.';
            $task->appendLog('Proceso detenido y tarea cancelada por el administrador.');
            $task->save();

            $this->cleanupTempFiles($task->output_file);

            return [
                'action' => 'cancelled',
                'message' => "Ingesta de '{$task->title}' cancelada y detenida.",
                'task' => $task->fresh(),
            ];
        }

        // Si ya no está activa, limpiar temporales residuales (respetando archivo .mp4 ya catalogado) y borrar registro
        $this->cleanupTempFiles($task->output_file);
        $titleName = $task->title;
        $task->delete();

        return [
            'action' => 'deleted',
            'message' => "Registro de '{$titleName}' eliminado del historial.",
        ];
    }

    /**
     * Procesa de principio a fin una tarea de ingesta en segundo plano.
     */
    public function processTask(int $taskId): bool
    {
        $this->ensureSchema();

        // Bloqueo atómico para evitar doble ejecución si corren tanto queue:work como ingestion_runner.php
        $lock = Cache::lock("media_ingestion_task_{$taskId}", 14400);
        if (!$lock->get()) {
            Log::info("MediaIngestionService::processTask: Tarea #{$taskId} ya está siendo procesada por otro worker.");
            return false;
        }

        try {
            $task = MediaIngestionTask::find($taskId);
            if (!$task || in_array($task->status, ['completed', 'cancelled'], true)) {
                return false;
            }

            $this->ensureWritableDirectory($task->target_folder);

            $task->status = 'downloading';
            $task->progress = 1;
            $task->download_speed = 'Conectando...';
            $task->eta = 'Calculando...';
            $task->started_at = $task->started_at ?: now();
            $task->error_log = null;
            $task->appendLog('Worker iniciado. Preparando descarga del flujo multimedia...');
            $task->save();

            $outputFile = $task->output_file;
            $tempDownloadFile = $outputFile . '.part';
            $this->cleanupTempFiles($outputFile);

            // 1. Descargar según el tipo de fuente
            $downloadedPath = $this->executeDownloadStage($task, $tempDownloadFile);

            // Verificar si fue cancelada durante la descarga
            $fresh = MediaIngestionTask::find($task->id);
            if (!$fresh || $fresh->status === 'cancelled') {
                $this->cleanupTempFiles($outputFile);
                return false;
            }

            if (!$downloadedPath || !file_exists($downloadedPath) || @filesize($downloadedPath) < 65536) {
                throw new \RuntimeException('El archivo descargado está vacío o no alcanzó el tamaño mínimo válido.');
            }

            // 2. Empaquetado / Compatibilidad HTML5 MP4 (H.264 + AAC + faststart)
            $task->refresh();
            $task->status = 'processing';
            $task->progress = max(90, (int) $task->progress);
            $task->download_speed = 'Empaquetando MP4...';
            $task->eta = 'Verificando códecs';
            $task->appendLog('Descarga completada. Verificando compatibilidad HTML5 (H.264 + AAC + faststart)...');
            $task->save();

            $this->ensureHtml5Mp4Compatibility($task, $downloadedPath, $outputFile);

            // Verificar cancelación tras empaquetado
            $fresh = MediaIngestionTask::find($task->id);
            if (!$fresh || $fresh->status === 'cancelled') {
                $this->cleanupTempFiles($outputFile);
                return false;
            }

            clearstatcache(true, $outputFile);
            if (!file_exists($outputFile) || !$this->isPlayableVideo($outputFile)) {
                throw new \RuntimeException('La validación FFprobe determinó que el archivo MP4 resultante no es reproducible.');
            }

            @chmod($outputFile, 0664);
            $finalSize = (int) (@filesize($outputFile) ?: 0);
            $durationSeconds = (int) round($this->probeDuration($outputFile));

            // 3. Metadatos, póster, .nfo e indexación en Title / Episode
            $task->refresh();
            $task->progress = 96;
            $task->file_size = $finalSize;
            $task->download_speed = 'Catalogando...';
            $task->eta = 'Finalizando';
            $task->appendLog("Archivo MP4 verificado (" . $this->formatBytes($finalSize) . ", {$durationSeconds}s). Resolviendo metadatos y catalogando...");
            $task->save();

            $catalogResult = $this->resolveMetadataAndCatalog($task, $outputFile, $durationSeconds);

            $task->refresh();
            $task->status = 'completed';
            $task->progress = 100;
            $task->download_speed = '0.0 MB/s';
            $task->eta = 'Completado';
            $task->file_size = $finalSize;
            $task->pid = null;
            $task->title_id = $catalogResult['title_id'] ?? null;
            $task->episode_id = $catalogResult['episode_id'] ?? null;
            $task->completed_at = now();
            $task->error_log = null;
            $task->appendLog("Ingesta finalizada con éxito. Catalogado como Title #{$task->title_id}" . ($task->episode_id ? " / Episode #{$task->episode_id}." : '.'));
            $task->save();

            $this->cleanupTempFiles($outputFile);

            return true;
        } catch (\Throwable $e) {
            Log::error("MediaIngestionService::processTask #{$taskId} falló: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $task = MediaIngestionTask::find($taskId);
            if ($task && !in_array($task->status, ['completed', 'cancelled'], true)) {
                $task->status = 'failed';
                $task->pid = null;
                $task->download_speed = '0.0 MB/s';
                $task->eta = 'Error';
                $task->error_log = Str::limit($e->getMessage(), 2000);
                $task->appendLog('ERROR: ' . $e->getMessage());
                $task->save();

                $this->cleanupTempFiles($task->output_file);
            }

            return false;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Ejecuta la etapa de descarga física eligiendo la herramienta óptima según el tipo de fuente.
     */
    protected function executeDownloadStage(MediaIngestionTask $task, string $tempFile): string
    {
        $sourceType = $task->source_type;
        $url = $task->source_url;

        if ($sourceType === 'hls_m3u8') {
            return $this->downloadHlsWithFfmpeg($task, $url, $tempFile);
        }

        if ($sourceType === 'web_standard' && $this->findBinary('yt-dlp') !== null) {
            return $this->downloadWithYtDlp($task, $url, $tempFile);
        }

        if (in_array($sourceType, ['direct_http', 'ott_feed'], true) || $this->findBinary('yt-dlp') === null) {
            if ($this->findBinary('aria2c') !== null) {
                return $this->downloadWithAria2c($task, $url, $tempFile);
            }
            if ($this->findBinary('curl') !== null) {
                return $this->downloadWithCurl($task, $url, $tempFile);
            }
            return $this->downloadWithPhpStream($task, $url, $tempFile);
        }

        return $this->downloadWithCurl($task, $url, $tempFile);
    }

    /**
     * Descarga de manifiesto HLS (.m3u8) usando FFmpeg con reconexión automática.
     */
    protected function downloadHlsWithFfmpeg(MediaIngestionTask $task, string $url, string $tempFile): string
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (!$ffmpeg) {
            throw new \RuntimeException('FFmpeg no está instalado en el servidor; es obligatorio para procesar flujos HLS (.m3u8).');
        }

        $hlsMp4Temp = preg_replace('/\.part$/', '.hls.mp4', $tempFile);
        $task->appendLog('Motor seleccionado: FFmpeg HLS remux (H.264/AAC).');
        $task->save();

        $argsCopy = [
            $ffmpeg, '-y',
            '-user_agent', 'Mozilla/5.0 (compatible; FlixHN-Ingest/1.0)',
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '5',
            '-i', $url,
            '-c', 'copy',
            '-bsf:a', 'aac_adtstoasc',
            '-movflags', '+faststart',
            $hlsMp4Temp,
        ];

        $result = $this->runMonitoredProcess($task, $argsCopy, 'ffmpeg', $hlsMp4Temp);
        if ($result['cancelled']) {
            @unlink($hlsMp4Temp);
            return '';
        }

        if ($result['success'] && file_exists($hlsMp4Temp) && @filesize($hlsMp4Temp) > 65536) {
            return $hlsMp4Temp;
        }

        @unlink($hlsMp4Temp);
        $task->appendLog('Remux directo HLS no compatible; iniciando transcodificación a MP4 (H.264 + AAC)...');
        $task->save();

        $argsTranscode = [
            $ffmpeg, '-y',
            '-user_agent', 'Mozilla/5.0 (compatible; FlixHN-Ingest/1.0)',
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '5',
            '-i', $url,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '192k', '-ac', '2',
            '-movflags', '+faststart',
            $hlsMp4Temp,
        ];

        $result2 = $this->runMonitoredProcess($task, $argsTranscode, 'ffmpeg', $hlsMp4Temp);
        if ($result2['cancelled']) {
            @unlink($hlsMp4Temp);
            return '';
        }

        if (!$result2['success'] || !file_exists($hlsMp4Temp) || @filesize($hlsMp4Temp) < 65536) {
            throw new \RuntimeException('FFmpeg no pudo procesar el flujo HLS: ' . Str::limit($result2['error'], 500));
        }

        return $hlsMp4Temp;
    }

    /**
     * Descarga con yt-dlp para fuentes web estándar seleccionando contenedor/códecs compatibles HTML5.
     */
    protected function downloadWithYtDlp(MediaIngestionTask $task, string $url, string $tempFile): string
    {
        $ytdlp = $this->findBinary('yt-dlp');
        if (!$ytdlp) {
            throw new \RuntimeException('yt-dlp no está instalado en el servidor.');
        }

        $baseNoExt = preg_replace('/\.part$/', '.ytdl', $tempFile);
        $template = $baseNoExt . '.%(ext)s';

        $task->appendLog('Motor seleccionado: yt-dlp (selección preferente H.264 + AAC MP4).');
        $task->save();

        $args = [
            $ytdlp,
            '--newline',
            '--no-playlist',
            '--no-warnings',
            '--concurrent-fragments', '4',
            '-f', 'bestvideo[vcodec^=avc1]+bestaudio[acodec^=mp4a]/best[ext=mp4]/best',
            '--merge-output-format', 'mp4',
            '-o', $template,
            $url,
        ];

        $result = $this->runMonitoredProcess($task, $args, 'yt-dlp', $baseNoExt . '.mp4');
        if ($result['cancelled']) {
            return '';
        }

        $candidates = glob($baseNoExt . '.*') ?: [];
        $downloaded = null;
        foreach ($candidates as $cand) {
            if (!str_ends_with($cand, '.part') && !str_ends_with($cand, '.ytdl') && is_file($cand) && @filesize($cand) > 65536) {
                $downloaded = $cand;
                break;
            }
        }

        if (!$result['success'] || !$downloaded) {
            throw new \RuntimeException('yt-dlp falló al descargar la fuente: ' . Str::limit($result['error'], 500));
        }

        return $downloaded;
    }

    /**
     * Descarga directa HTTP acelerada con aria2c.
     */
    protected function downloadWithAria2c(MediaIngestionTask $task, string $url, string $tempFile): string
    {
        $aria2c = $this->findBinary('aria2c');
        $dir = dirname($tempFile);
        $fileName = basename($tempFile);

        $task->appendLog('Motor seleccionado: aria2c (descarga directa HTTP multi-hilo).');
        $task->save();

        $args = [
            $aria2c,
            '--allow-overwrite=true',
            '--auto-file-renaming=false',
            '--file-allocation=none',
            '--max-connection-per-server=8',
            '--split=8',
            '--min-split-size=2M',
            '--connect-timeout=25',
            '--timeout=60',
            '--summary-interval=1',
            '--console-log-level=notice',
            '--dir=' . $dir,
            '--out=' . $fileName,
            $url,
        ];

        $result = $this->runMonitoredProcess($task, $args, 'aria2c', $tempFile);
        if ($result['cancelled']) {
            return '';
        }

        if (!$result['success'] || !file_exists($tempFile) || @filesize($tempFile) < 65536) {
            @unlink($tempFile);
            if ($this->findBinary('curl') !== null) {
                $task->appendLog('aria2c reportó error; reintentando con curl estándar...');
                $task->save();
                return $this->downloadWithCurl($task, $url, $tempFile);
            }
            throw new \RuntimeException('aria2c falló al descargar el archivo: ' . Str::limit($result['error'], 500));
        }

        return $tempFile;
    }

    /**
     * Descarga directa HTTP usando curl.
     */
    protected function downloadWithCurl(MediaIngestionTask $task, string $url, string $tempFile): string
    {
        $curl = $this->findBinary('curl');
        $task->appendLog('Motor seleccionado: curl (descarga directa HTTP).');
        $task->save();

        $args = [
            $curl,
            '-L',
            '--fail',
            '--connect-timeout', '25',
            '--retry', '3',
            '--retry-delay', '2',
            '-A', 'Mozilla/5.0 (compatible; FlixHN-Ingest/1.0)',
            '-o', $tempFile,
            $url,
        ];

        $result = $this->runMonitoredProcess($task, $args, 'curl', $tempFile);
        if ($result['cancelled']) {
            return '';
        }

        if (!$result['success'] || !file_exists($tempFile) || @filesize($tempFile) < 65536) {
            throw new \RuntimeException('curl falló al descargar el archivo: ' . Str::limit($result['error'], 500));
        }

        return $tempFile;
    }

    /**
     * Fallback puro en PHP para descarga HTTP directa cuando no hay aria2c/curl.
     */
    protected function downloadWithPhpStream(MediaIngestionTask $task, string $url, string $tempFile): string
    {
        $task->appendLog('Motor seleccionado: PHP HTTP Stream fallback.');
        $task->save();

        $totalBytes = 0;
        $headers = @get_headers($url, true);
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === 'content-length') {
                    $totalBytes = (int) (is_array($v) ? end($v) : $v);
                }
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 45,
                'header' => "User-Agent: FlixHN-Ingest/1.0\r\nAccept: */*\r\n",
            ],
        ]);

        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) {
            throw new \RuntimeException('No fue posible abrir conexión HTTP con la URL de origen.');
        }

        $out = @fopen($tempFile, 'wb');
        if (!$out) {
            @fclose($in);
            throw new \RuntimeException("No fue posible escribir en {$tempFile}.");
        }

        $startTime = microtime(true);
        $lastUpdate = $startTime;
        $downloaded = 0;

        while (!feof($in)) {
            $fresh = MediaIngestionTask::select(['status'])->find($task->id);
            if ($fresh && $fresh->status === 'cancelled') {
                @fclose($in);
                @fclose($out);
                @unlink($tempFile);
                return '';
            }

            $buf = fread($in, 1024 * 1024);
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($out, $buf);
            $downloaded += strlen($buf);

            $now = microtime(true);
            if (($now - $lastUpdate) >= 1.5) {
                $elapsed = max(0.1, $now - $startTime);
                $speedBps = $downloaded / $elapsed;
                $pct = $totalBytes > 0 ? min(89, max(1, (int) round(($downloaded / $totalBytes) * 89))) : min(85, max(5, (int) ($task->progress + 2)));
                $eta = ($totalBytes > 0 && $speedBps > 0) ? $this->formatSeconds((int) (($totalBytes - $downloaded) / $speedBps)) : 'Descargando...';

                $task->update([
                    'progress' => $pct,
                    'download_speed' => number_format($speedBps / 1048576, 1) . ' MB/s',
                    'eta' => $eta,
                    'file_size' => $downloaded,
                ]);
                $lastUpdate = $now;
            }
        }

        @fclose($in);
        @fclose($out);

        return $tempFile;
    }

    /**
     * Ejecuta un subproceso con monitoreo reactivo de progreso, velocidad, ETA y cancelación.
     *
     * @return array{success: bool, cancelled: bool, exit_code: int, error: string}
     */
    protected function runMonitoredProcess(MediaIngestionTask $task, array $commandArgs, string $tool, string $watchFile): array
    {
        $process = new Process($commandArgs);
        $process->setTimeout(14400);
        $process->start();

        $task->update(['pid' => $process->getPid()]);

        $lastUpdate = microtime(true);
        $lastBytes = 0;
        $totalDurationSec = 0.0;
        $capturedError = '';

        while ($process->isRunning()) {
            $fresh = MediaIngestionTask::select(['status'])->find($task->id);
            if ($fresh && $fresh->status === 'cancelled') {
                $process->stop(3, defined('SIGKILL') ? SIGKILL : 9);
                return ['success' => false, 'cancelled' => true, 'exit_code' => -1, 'error' => 'Cancelado'];
            }

            $chunk = $process->getIncrementalOutput() . "\n" . $process->getIncrementalErrorOutput();
            if (trim($chunk) !== '') {
                $capturedError = substr($capturedError . "\n" . $chunk, -4000);
            }

            if ($tool === 'ffmpeg' && $totalDurationSec <= 0 && preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}\.\d+)/', $chunk, $dm)) {
                $totalDurationSec = ((int) $dm[1] * 3600) + ((int) $dm[2] * 60) + (float) $dm[3];
            }

            $now = microtime(true);
            if (($now - $lastUpdate) >= 1.5) {
                $parsed = $this->parseProcessProgress($tool, $chunk, $watchFile, $lastBytes, $now - $lastUpdate, $totalDurationSec);

                $update = [];
                if (isset($parsed['progress']) && $parsed['progress'] > 0) {
                    $update['progress'] = min(89, max((int) $task->progress, (int) $parsed['progress']));
                }
                if (!empty($parsed['speed'])) {
                    $update['download_speed'] = $parsed['speed'];
                }
                if (!empty($parsed['eta'])) {
                    $update['eta'] = $parsed['eta'];
                }
                if (isset($parsed['bytes']) && $parsed['bytes'] > 0) {
                    $update['file_size'] = $parsed['bytes'];
                }

                if (!empty($update)) {
                    $task->update($update);
                }

                clearstatcache(true, $watchFile);
                $lastBytes = file_exists($watchFile) ? (int) (@filesize($watchFile) ?: 0) : $lastBytes;
                $lastUpdate = $now;
            }

            usleep(300000);
        }

        $tail = $process->getIncrementalOutput() . "\n" . $process->getIncrementalErrorOutput();
        if (trim($tail) !== '') {
            $capturedError = substr($capturedError . "\n" . $tail, -4000);
        }

        $fresh = MediaIngestionTask::select(['status'])->find($task->id);
        if ($fresh && $fresh->status === 'cancelled') {
            return ['success' => false, 'cancelled' => true, 'exit_code' => -1, 'error' => 'Cancelado'];
        }

        $exitCode = (int) ($process->getExitCode() ?? 1);

        return [
            'success' => $exitCode === 0,
            'cancelled' => false,
            'exit_code' => $exitCode,
            'error' => trim($capturedError),
        ];
    }

    /**
     * Extrae progreso porcentual, velocidad y ETA desde la salida de yt-dlp, aria2c, curl o ffmpeg.
     */
    protected function parseProcessProgress(
        string $tool,
        string $output,
        string $watchFile,
        int $lastBytes,
        float $elapsedSec,
        float $totalDurationSec
    ): array {
        $data = [];
        clearstatcache(true, $watchFile);
        $currentBytes = file_exists($watchFile) ? (int) (@filesize($watchFile) ?: 0) : 0;
        if ($currentBytes > 0) {
            $data['bytes'] = $currentBytes;
        }

        if ($tool === 'yt-dlp') {
            if (preg_match_all('/\[download\]\s+([0-9\.]+)%(?:.*?at\s+([0-9\.]+[KMG]?i?B\/s))?(?:.*?ETA\s+([0-9:]+))?/i', $output, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $rawPct = (float) $last[1];
                $data['progress'] = (int) round($rawPct * 0.89);
                if (!empty($last[2])) {
                    $data['speed'] = $last[2];
                }
                if (!empty($last[3])) {
                    $data['eta'] = $last[3];
                }
            }
        } elseif ($tool === 'aria2c') {
            if (preg_match_all('/\((\d{1,3})%\).*?DL:([0-9\.]+[KMG]?i?B)(?:.*?ETA:([0-9hms]+))?/i', $output, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $data['progress'] = (int) round(((int) $last[1]) * 0.89);
                $data['speed'] = $last[2] . '/s';
                if (!empty($last[3])) {
                    $data['eta'] = $last[3];
                }
            }
        } elseif ($tool === 'ffmpeg') {
            if (preg_match_all('/time=(\d{2}):(\d{2}):(\d{2}\.\d+)(?:.*?speed=\s*([0-9\.]+x))?/i', $output, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $curSec = ((int) $last[1] * 3600) + ((int) $last[2] * 60) + (float) $last[3];
                if ($totalDurationSec > 0) {
                    $data['progress'] = (int) round(min(100, ($curSec / $totalDurationSec) * 100) * 0.89);
                    $rem = max(0, (int) ($totalDurationSec - $curSec));
                    $data['eta'] = $this->formatSeconds($rem);
                }
                if (!empty($last[4])) {
                    $data['speed'] = $last[4];
                }
            }
        } elseif ($tool === 'curl') {
            if (preg_match_all('/\b(\d{1,3})\s+[0-9\.]+[kMG]?\s+\d{1,3}\s+[0-9\.]+[kMG]?\s+0\s+0\s+([0-9\.]+[kMG]?)\s+0\s+[0-9:-]+\s+[0-9:-]+\s+([0-9:-]+)/', $output, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $data['progress'] = (int) round(((int) $last[1]) * 0.89);
                $data['speed'] = $last[2] . '/s';
                $data['eta'] = $last[3];
            }
        }

        if (empty($data['speed']) && $elapsedSec > 0 && $currentBytes > $lastBytes) {
            $bps = ($currentBytes - $lastBytes) / $elapsedSec;
            $data['speed'] = number_format($bps / 1048576, 1) . ' MB/s';
        }

        return $data;
    }

    /**
     * Garantiza que el archivo final sea .mp4 compatible con HTML5 (H.264 + AAC + faststart).
     */
    protected function ensureHtml5Mp4Compatibility(MediaIngestionTask $task, string $sourceFile, string $outputFile): void
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (!$ffmpeg) {
            if ($sourceFile !== $outputFile) {
                @rename($sourceFile, $outputFile);
            }
            return;
        }

        $codecs = $this->probeCodecs($sourceFile);
        $vCodec = strtolower($codecs['video'] ?? '');
        $aCodec = strtolower($codecs['audio'] ?? '');
        $isHtml5Compatible = ($vCodec === 'h264') && ($aCodec === '' || $aCodec === 'aac');

        $packagedTemp = $outputFile . '.faststart.mp4';
        @unlink($packagedTemp);

        if ($isHtml5Compatible) {
            $task->appendLog("Códecs compatibles detectados (video: {$vCodec}, audio: " . ($aCodec ?: 'none') . "). Aplicando remux rápido MP4 +faststart...");
            $task->save();

            $args = [
                $ffmpeg, '-y',
                '-i', $sourceFile,
                '-c', 'copy',
                '-movflags', '+faststart',
                $packagedTemp,
            ];
        } else {
            $task->appendLog("Transcodificando a perfil HTML5 universal (H.264 + AAC) desde video={$vCodec}, audio={$aCodec}...");
            $task->save();

            $args = [
                $ffmpeg, '-y',
                '-i', $sourceFile,
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '22',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-b:a', '192k',
                '-ac', '2',
                '-movflags', '+faststart',
                $packagedTemp,
            ];
        }

        $proc = new Process($args);
        $proc->setTimeout(14400);
        $proc->start();
        $task->update(['pid' => $proc->getPid(), 'progress' => 93]);

        while ($proc->isRunning()) {
            $fresh = MediaIngestionTask::select(['status'])->find($task->id);
            if ($fresh && $fresh->status === 'cancelled') {
                $proc->stop(3, defined('SIGKILL') ? SIGKILL : 9);
                @unlink($packagedTemp);
                return;
            }
            usleep(400000);
        }

        if ($proc->isSuccessful() && file_exists($packagedTemp) && @filesize($packagedTemp) > 65536) {
            if (file_exists($outputFile)) {
                @unlink($outputFile);
            }
            @rename($packagedTemp, $outputFile);
            if ($sourceFile !== $outputFile && file_exists($sourceFile)) {
                @unlink($sourceFile);
            }
            return;
        }

        @unlink($packagedTemp);

        if ($isHtml5Compatible && $this->isPlayableVideo($sourceFile)) {
            if ($sourceFile !== $outputFile) {
                @rename($sourceFile, $outputFile);
            }
            return;
        }

        throw new \RuntimeException('Error empaquetando a MP4 HTML5: ' . Str::limit($proc->getErrorOutput(), 500));
    }

    /**
     * Resuelve metadatos (local .nfo, TMDb por IMDb ID o título, Metahub), guarda .nfo/póster y cataloga en Title/Episode.
     *
     * @return array{title_id: int, episode_id: ?int}
     */
    protected function resolveMetadataAndCatalog(MediaIngestionTask $task, string $outputFile, int $durationSeconds): array
    {
        $isSeries = $task->content_type === 'series';
        $dirPath = dirname($outputFile);
        $seriesRootDir = $isSeries ? dirname($dirPath) : $dirPath;

        $nfoMeta = $this->readLocalNfo($isSeries ? $seriesRootDir : $dirPath, basename($outputFile));

        $imdbId = $task->imdb_id ?: ($nfoMeta['imdb_id'] ?? null);
        $tmdbId = $task->tmdb_id ?: ($nfoMeta['tmdb_id'] ?? null);
        $externalMeta = $this->fetchExternalMetadata($task->title, $isSeries ? 'series' : 'movie', $imdbId, $tmdbId, $task->release_year);

        $finalTitle = trim((string) ($externalMeta['title'] ?? $nfoMeta['title'] ?? $task->title));
        $finalYear = (int) ($task->release_year ?: ($externalMeta['release_year'] ?? $nfoMeta['release_year'] ?? date('Y')));
        $finalOverview = trim((string) (
            $task->overview
            ?: ($externalMeta['overview'] ?? '')
            ?: ($nfoMeta['overview'] ?? '')
            ?: ($isSeries
                ? "Serie {$finalTitle} ({$finalYear}) disponible en la biblioteca local de FlixHN."
                : "Película {$finalTitle} ({$finalYear}) disponible en la biblioteca local de FlixHN.")
        ));
        $finalGenre = $externalMeta['genre'] ?? $nfoMeta['genre'] ?? ($isSeries ? 'Series' : 'Películas');
        $finalRating = (float) ($externalMeta['rating'] ?? $nfoMeta['rating'] ?? 8.0);
        $finalTmdbId = $tmdbId ?: ($externalMeta['tmdb_id'] ?? null);
        $finalImdbId = $imdbId ?: ($externalMeta['imdb_id'] ?? null);

        $posterCandidate = $externalMeta['poster_url'] ?? $nfoMeta['poster_url'] ?? null;
        $backdropCandidate = $externalMeta['backdrop_url'] ?? $nfoMeta['backdrop_url'] ?? null;

        if (!$posterCandidate && $finalImdbId) {
            $posterCandidate = "https://images.metahub.space/poster/medium/{$finalImdbId}/img";
        }
        if (!$backdropCandidate && $finalImdbId) {
            $backdropCandidate = "https://images.metahub.space/background/medium/{$finalImdbId}/img";
        }

        $posterSlug = Str::slug($finalTitle . '-' . $finalYear) ?: ('media-' . $task->id);
        $posterUrl = '/placeholder-poster.png';
        if ($posterCandidate) {
            try {
                $downloadedPoster = app(PosterDownloadService::class)->downloadAndStorePoster(
                    slug: $posterSlug,
                    candidateUrl: $posterCandidate,
                    title: $finalTitle,
                    tmdbId: $finalTmdbId ? (string) $finalTmdbId : null
                );
                if (!empty($downloadedPoster)) {
                    $posterUrl = $downloadedPoster;
                    $storedPhysical = storage_path('app/public/' . ltrim(str_replace('/storage/', '', $downloadedPoster), '/'));
                    $localPosterTarget = ($isSeries ? $seriesRootDir : $dirPath) . '/poster.jpg';
                    if (file_exists($storedPhysical) && !file_exists($localPosterTarget)) {
                        @copy($storedPhysical, $localPosterTarget);
                        @chmod($localPosterTarget, 0664);
                    }
                }
            } catch (\Throwable $e) {
                $posterUrl = $posterCandidate;
            }
        }
        $backdropUrl = $backdropCandidate ?: $posterUrl;

        $this->writeLocalNfo(
            $isSeries ? ($seriesRootDir . '/tvshow.nfo') : preg_replace('/\.[^.]+$/', '.nfo', $outputFile),
            $isSeries ? 'tvshow' : 'movie',
            [
                'title' => $finalTitle,
                'year' => $finalYear,
                'plot' => $finalOverview,
                'genre' => $finalGenre,
                'rating' => $finalRating,
                'imdb_id' => $finalImdbId,
                'tmdb_id' => $finalTmdbId,
                'poster_url' => $posterCandidate,
                'backdrop_url' => $backdropCandidate,
                'duration_seconds' => $durationSeconds,
            ]
        );

        $categorySlug = $isSeries ? 'series' : 'peliculas';
        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            [
                'name' => $isSeries ? 'Series' : 'Películas',
                'type' => $isSeries ? 'series' : 'movie',
                'folder_path' => $isSeries ? 'series' : 'movies',
                'sort_order' => $isSeries ? 2 : 1,
            ]
        );

        $slug = $isSeries
            ? (Str::slug($finalTitle) ?: ('series-' . $task->id))
            : (Str::slug($finalTitle . '-' . $finalYear) ?: ('movie-' . $task->id));

        if ($isSeries) {
            $titleModel = Title::where('type', 'series')
                ->where(function ($q) use ($slug, $finalTitle, $finalImdbId, $finalTmdbId) {
                    $q->where('slug', $slug)->orWhere('name', $finalTitle);
                    if ($finalImdbId) {
                        $q->orWhere('imdb_id', $finalImdbId);
                    }
                    if ($finalTmdbId) {
                        $q->orWhere('tmdb_id', $finalTmdbId);
                    }
                })
                ->first();
        } else {
            $titleModel = Title::where('source_path', $outputFile)
                ->orWhere('file_path', $outputFile)
                ->orWhere('slug', $slug)
                ->first();
        }

        if (!$titleModel) {
            $titleModel = new Title();
            $titleModel->slug = $slug;
        }

        $titleModel->name = $finalTitle;
        $titleModel->sort_title = $finalTitle;
        $titleModel->type = $isSeries ? 'series' : 'movie';
        $titleModel->provider = 'local';
        $titleModel->source_type = 'local';
        $titleModel->category_id = $category->id;
        $titleModel->description = $finalOverview;
        $titleModel->release_year = $finalYear;
        $titleModel->genre = $finalGenre;
        $titleModel->community_rating = $finalRating;
        $titleModel->duration_seconds = $isSeries ? 0 : ($durationSeconds ?: 5400);
        if ($finalImdbId) {
            $titleModel->imdb_id = $finalImdbId;
        }
        if ($finalTmdbId) {
            $titleModel->tmdb_id = (string) $finalTmdbId;
        }
        if ($posterUrl !== '/placeholder-poster.png' || empty($titleModel->poster_url)) {
            $titleModel->poster_url = $posterUrl;
        }
        if ($backdropUrl !== '/placeholder-poster.png' || empty($titleModel->backdrop_url)) {
            $titleModel->backdrop_url = $backdropUrl;
        }

        $titleModel->source_path = $isSeries ? $seriesRootDir : $outputFile;
        $titleModel->file_path = $outputFile;
        $titleModel->stream_path = $titleModel->stream_path ?: '';
        $titleModel->save();

        $titleModel->update(['stream_path' => "/api/stream/title/{$titleModel->id}"]);

        $episodeId = null;
        if ($isSeries) {
            $seasonNum = max(0, (int) ($task->season_number ?? 1));
            $episodeNum = max(1, (int) ($task->episode_number ?? 1));

            $season = Season::firstOrCreate(
                ['title_id' => $titleModel->id, 'season_number' => $seasonNum],
                ['title' => $seasonNum === 0 ? 'Especiales' : "Temporada {$seasonNum}"]
            );

            $epName = $task->episode_title ?: sprintf('Episodio %02d', $episodeNum);
            $episode = Episode::updateOrCreate(
                [
                    'title_id' => $titleModel->id,
                    'season_id' => $season->id,
                    'episode_number' => $episodeNum,
                ],
                [
                    'title' => $epName,
                    'sort_title' => $epName,
                    'description' => $task->overview ?: "Temporada {$seasonNum}, Episodio {$episodeNum} de {$finalTitle}.",
                    'file_path' => $outputFile,
                    'source_path' => $outputFile,
                    'source_type' => 'local',
                    'duration_seconds' => $durationSeconds ?: 2400,
                    'thumbnail_url' => $backdropUrl ?: $posterUrl,
                    'stream_path' => '',
                ]
            );

            $episode->update(['stream_path' => "/api/stream/episode/{$episode->id}"]);
            $episodeId = $episode->id;
        }

        try {
            if (Schema::hasTable('media_items')) {
                MediaItem::updateOrCreate(
                    ['title_id' => $titleModel->id],
                    [
                        'title' => $finalTitle,
                        'original_title' => $finalTitle,
                        'type' => $titleModel->type,
                        'provider' => 'local',
                        'external_id' => 'ingestion-' . $task->id,
                        'file_path' => $outputFile,
                        'stream_url' => "/api/stream/title/{$titleModel->id}",
                        'overview' => $finalOverview,
                        'poster_url' => $titleModel->poster_url,
                        'backdrop_url' => $titleModel->backdrop_url,
                        'release_year' => $finalYear,
                        'genre' => $finalGenre,
                        'duration_seconds' => $durationSeconds ?: 5400,
                        'quality' => $task->quality ?: '1080p',
                        'file_size' => (int) (@filesize($outputFile) ?: 0),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::debug('MediaIngestionService: MediaItem sync omitted: ' . $e->getMessage());
        }

        foreach (['catalog_home_base', 'catalog_home_kids', 'catalog_home', 'titles_all', 'catalog_all'] as $cacheKey) {
            Cache::forget($cacheKey);
        }

        return [
            'title_id' => $titleModel->id,
            'episode_id' => $episodeId,
        ];
    }

    /**
     * Consulta metadatos en TMDb (por IMDb ID, TMDb ID o título).
     */
    protected function fetchExternalMetadata(
        string $title,
        string $contentType,
        ?string $imdbId = null,
        ?string $tmdbId = null,
        ?int $year = null
    ): array {
        $apiKey = '';
        $language = 'es-ES';

        try {
            $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
            $apiKey = trim((string) ($tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? config('flix.tmdb.api_key', '')));
            $language = (string) ($tmdbPlugin?->settings['language'] ?? config('flix.tmdb.language', 'es-ES'));
        } catch (\Throwable $e) {
            $apiKey = trim((string) config('flix.tmdb.api_key', ''));
        }

        if ($apiKey === '' || str_starts_with($apiKey, 'tmdb_live_key')) {
            return [];
        }

        try {
            $item = null;

            if ($imdbId) {
                $resp = Http::timeout(8)->get("https://api.themoviedb.org/3/find/{$imdbId}", [
                    'api_key' => $apiKey,
                    'external_source' => 'imdb_id',
                    'language' => $language,
                ]);
                if ($resp->successful()) {
                    $movieResults = $resp->json('movie_results') ?? [];
                    $tvResults = $resp->json('tv_results') ?? [];
                    $item = $contentType === 'series'
                        ? ($tvResults[0] ?? $movieResults[0] ?? null)
                        : ($movieResults[0] ?? $tvResults[0] ?? null);
                }
            }

            if (!$item && $tmdbId && is_numeric($tmdbId)) {
                $endpoint = $contentType === 'series' ? "tv/{$tmdbId}" : "movie/{$tmdbId}";
                $resp = Http::timeout(8)->get("https://api.themoviedb.org/3/{$endpoint}", [
                    'api_key' => $apiKey,
                    'language' => $language,
                ]);
                if ($resp->successful()) {
                    $item = $resp->json();
                }
            }

            if (!$item) {
                $endpoint = $contentType === 'series' ? 'search/tv' : 'search/movie';
                $params = [
                    'api_key' => $apiKey,
                    'query' => $title,
                    'language' => $language,
                    'include_adult' => false,
                ];
                if ($year) {
                    $params[$contentType === 'series' ? 'first_air_date_year' : 'year'] = $year;
                }
                $resp = Http::timeout(8)->get("https://api.themoviedb.org/3/{$endpoint}", $params);
                if ($resp->successful() && !empty($resp->json('results.0'))) {
                    $item = $resp->json('results.0');
                }
            }

            if (!is_array($item)) {
                return [];
            }

            $dateField = $item['release_date'] ?? $item['first_air_date'] ?? '';
            $parsedYear = strlen($dateField) >= 4 ? (int) substr($dateField, 0, 4) : null;

            return [
                'title' => $item['title'] ?? $item['name'] ?? null,
                'overview' => $item['overview'] ?? null,
                'release_year' => $parsedYear,
                'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
                'poster_url' => !empty($item['poster_path']) ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}" : null,
                'backdrop_url' => !empty($item['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$item['backdrop_path']}" : null,
                'tmdb_id' => $item['id'] ?? null,
                'imdb_id' => $imdbId,
            ];
        } catch (\Throwable $e) {
            Log::warning('MediaIngestionService::fetchExternalMetadata error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Lee metadatos básicos desde un archivo .nfo local si existe en la carpeta destino.
     */
    protected function readLocalNfo(string $dir, string $videoFileName = ''): array
    {
        $candidates = [];
        if ($videoFileName !== '') {
            $base = pathinfo($videoFileName, PATHINFO_FILENAME);
            $candidates[] = "{$dir}/{$base}.nfo";
        }
        $candidates[] = "{$dir}/tvshow.nfo";
        $candidates[] = "{$dir}/movie.nfo";

        $nfoFile = null;
        foreach ($candidates as $cand) {
            if (is_file($cand) && is_readable($cand) && @filesize($cand) > 10) {
                $nfoFile = $cand;
                break;
            }
        }

        if (!$nfoFile) {
            return [];
        }

        $xml = @file_get_contents($nfoFile);
        if (!$xml) {
            return [];
        }

        $clean = static function (?string $v): string {
            if (!$v) {
                return '';
            }
            $v = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', $v);
            return trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        };

        $meta = [];
        if (preg_match('/<title>(.*?)<\/title>/is', $xml, $m)) {
            $meta['title'] = $clean($m[1]);
        }
        if (preg_match('/<plot>(.*?)<\/plot>/is', $xml, $m)) {
            $meta['overview'] = $clean($m[1]);
        }
        if (preg_match('/<year>(\d{4})<\/year>/is', $xml, $m)) {
            $meta['release_year'] = (int) $m[1];
        }
        if (preg_match('/<imdbid>(tt\d+)<\/imdbid>/is', $xml, $m) || preg_match('/<uniqueid[^>]*type=["\']imdb["\'][^>]*>(tt\d+)<\/uniqueid>/is', $xml, $m)) {
            $meta['imdb_id'] = strtolower(trim($m[1]));
        }
        if (preg_match('/<tmdbid>(\d+)<\/tmdbid>/is', $xml, $m) || preg_match('/<uniqueid[^>]*type=["\']tmdb["\'][^>]*>(\d+)<\/uniqueid>/is', $xml, $m)) {
            $meta['tmdb_id'] = trim($m[1]);
        }
        if (preg_match('/<rating>([0-9\.]+)<\/rating>/is', $xml, $m)) {
            $meta['rating'] = (float) $m[1];
        }
        if (preg_match('/<genre>(.*?)<\/genre>/is', $xml, $m)) {
            $meta['genre'] = $clean($m[1]);
        }

        return $meta;
    }

    /**
     * Escribe un archivo .nfo compatible con Kodi/Emby/LocalMediaScannerService.
     */
    protected function writeLocalNfo(string $nfoPath, string $rootTag, array $meta): void
    {
        try {
            $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lines = [
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
                "<{$rootTag}>",
                '  <title>' . $esc($meta['title'] ?? '') . '</title>',
                '  <year>' . $esc($meta['year'] ?? '') . '</year>',
                '  <plot>' . $esc($meta['plot'] ?? '') . '</plot>',
                '  <genre>' . $esc($meta['genre'] ?? '') . '</genre>',
                '  <rating>' . $esc($meta['rating'] ?? 8.0) . '</rating>',
            ];
            if (!empty($meta['imdb_id'])) {
                $lines[] = '  <imdbid>' . $esc($meta['imdb_id']) . '</imdbid>';
            }
            if (!empty($meta['tmdb_id'])) {
                $lines[] = '  <tmdbid>' . $esc($meta['tmdb_id']) . '</tmdbid>';
            }
            if (!empty($meta['duration_seconds'])) {
                $lines[] = '  <durationinseconds>' . (int) $meta['duration_seconds'] . '</durationinseconds>';
            }
            if (!empty($meta['poster_url'])) {
                $lines[] = '  <thumb aspect="poster">' . $esc($meta['poster_url']) . '</thumb>';
            }
            if (!empty($meta['backdrop_url'])) {
                $lines[] = '  <fanart><thumb>' . $esc($meta['backdrop_url']) . '</thumb></fanart>';
            }
            $lines[] = "</{$rootTag}>";

            @file_put_contents($nfoPath, implode("\n", $lines) . "\n");
            @chmod($nfoPath, 0664);
        } catch (\Throwable $e) {
            Log::debug('MediaIngestionService::writeLocalNfo skipped: ' . $e->getMessage());
        }
    }

    /**
     * Valida que el disco seleccionado pertenezca estrictamente a los discos autorizados y esté escribible.
     */
    protected function validateAndResolveDisk(string $requestedDisk): string
    {
        $normalized = rtrim(str_replace('\\', '/', trim($requestedDisk)), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new \InvalidArgumentException('El disco de destino especificado no es válido.');
        }

        $disks = $this->getAvailableDisks();
        $matched = null;
        foreach ($disks as $disk) {
            if (strtolower($disk['path']) === strtolower($normalized)) {
                $matched = $disk;
                break;
            }
        }

        if (!$matched) {
            throw new \InvalidArgumentException("El disco '{$normalized}' no está dentro de los volúmenes permitidos de FlixHN.");
        }

        if (!$matched['mounted']) {
            throw new \InvalidArgumentException("El volumen {$matched['path']} no está montado actualmente en el contenedor.");
        }

        if (!$matched['writable']) {
            throw new \InvalidArgumentException("El volumen {$matched['path']} no tiene permisos de escritura para PHP/www-data.");
        }

        return $matched['path'];
    }

    /**
     * Construye carpeta destino y nombre final .mp4 siguiendo la estructura de Películas y Series de FlixHN.
     *
     * @return array{0: string, 1: string}
     */
    protected function buildDestinationPaths(
        string $targetDisk,
        string $contentType,
        string $title,
        ?int $releaseYear,
        ?int $seasonNumber,
        ?int $episodeNumber,
        string $customSubfolder = ''
    ): array {
        $safeTitle = $this->sanitizeFilename($title);
        $cleanSub = trim(str_replace(['..', '\\'], ['', '/'], $customSubfolder), '/');

        if ($contentType === 'series') {
            $sNum = max(0, (int) ($seasonNumber ?? 1));
            $eNum = max(1, (int) ($episodeNumber ?? 1));
            $seasonLabel = $sNum === 0 ? 'Especiales' : sprintf('Temporada %02d', $sNum);
            $baseGroup = $cleanSub !== '' ? $cleanSub : 'Series';
            $folder = "{$targetDisk}/{$baseGroup}/{$safeTitle}/{$seasonLabel}";
            $fileName = sprintf('%s - S%02dE%02d.mp4', $safeTitle, $sNum, $eNum);
            return [$folder, "{$folder}/{$fileName}"];
        }

        $baseGroup = $cleanSub !== '' ? $cleanSub : 'Peliculas';
        $movieDirName = $releaseYear ? "{$safeTitle} ({$releaseYear})" : $safeTitle;
        $folder = "{$targetDisk}/{$baseGroup}/{$movieDirName}";
        $fileName = "{$movieDirName}.mp4";

        return [$folder, "{$folder}/{$fileName}"];
    }

    protected function ensureWritableDirectory(string $dir): void
    {
        if (!File::isDirectory($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("No se pudo crear el directorio de destino: {$dir}. Verifique permisos en el volumen.");
            }
        }
        @chmod($dir, 0775);

        if (!is_writable($dir)) {
            throw new \RuntimeException("Sin permisos de escritura en la ruta destino: {$dir}.");
        }
    }

    protected function launchDetachedRunner(int $taskId): void
    {
        $runnerPath = base_path('ingestion_runner.php');
        if (!file_exists($runnerPath)) {
            return;
        }

        $phpBinary = 'php';
        if (file_exists('/usr/local/bin/php')) {
            $phpBinary = '/usr/local/bin/php';
        } elseif (file_exists('/usr/bin/php')) {
            $phpBinary = '/usr/bin/php';
        } elseif (defined('PHP_BINARY') && !str_contains(PHP_BINARY, 'php-fpm')) {
            $phpBinary = PHP_BINARY;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B \"\" \"{$phpBinary}\" \"{$runnerPath}\" {$taskId} > NUL 2>&1";
            @pclose(@popen($cmd, 'r'));
        } else {
            $cmd = 'nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($runnerPath) . ' ' . (int) $taskId . ' > /dev/null 2>&1 &';
            @exec($cmd);
        }
    }

    protected function terminatePid(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            @exec("taskkill /F /T /PID {$pid} > NUL 2>&1");
        } else {
            @exec("kill -9 {$pid} > /dev/null 2>&1");
        }
    }

    protected function cleanupTempFiles(?string $outputFile): void
    {
        if (empty($outputFile)) {
            return;
        }

        $patterns = [
            $outputFile . '.part',
            $outputFile . '.part.aria2',
            $outputFile . '.faststart.mp4',
            preg_replace('/\.mp4$/i', '.mp4.hls.mp4', $outputFile),
            preg_replace('/\.mp4$/i', '.mp4.ytdl*', $outputFile),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if ($file !== $outputFile && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    protected function isConfiguredInternalHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $urls = array_filter([
            ServerSetting::get('media_server_url'),
            ServerSetting::get('emby_server_url'),
            config('app.url'),
        ]);

        foreach ($urls as $u) {
            $h = strtolower((string) (parse_url((string) $u, PHP_URL_HOST) ?? ''));
            if ($h !== '' && $h === $host) {
                return true;
            }
        }

        return false;
    }

    protected function detectSeasonEpisode(string $text): array
    {
        if (preg_match('/S(\d{1,2})\s*E(\d{1,3})/i', $text, $m)) {
            return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
        }
        if (preg_match('/\b(\d{1,2})x(\d{1,3})\b/i', $text, $m)) {
            return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
        }
        return ['season' => 1, 'episode' => 1];
    }

    protected function sanitizeFilename(string $name): string
    {
        $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/', ' ', $name);
        $clean = trim(preg_replace('/\s+/', ' ', (string) $clean));
        $clean = trim($clean, '. ');
        return $clean !== '' ? Str::limit($clean, 140, '') : ('Media_' . time());
    }

    protected function probeCodecs(string $filePath): array
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (!$ffprobe || !file_exists($filePath)) {
            return ['video' => '', 'audio' => ''];
        }

        $vProc = new Process([$ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'default=noprint_wrappers=1:nokey=1', $filePath]);
        $vProc->setTimeout(20);
        $vProc->run();

        $aProc = new Process([$ffprobe, '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=codec_name', '-of', 'default=noprint_wrappers=1:nokey=1', $filePath]);
        $aProc->setTimeout(20);
        $aProc->run();

        return [
            'video' => trim($vProc->getOutput()),
            'audio' => trim($aProc->getOutput()),
        ];
    }

    protected function probeDuration(string $filePath): float
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (!$ffprobe || !file_exists($filePath)) {
            return 0.0;
        }

        $proc = new Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $filePath]);
        $proc->setTimeout(20);
        $proc->run();

        $out = trim($proc->getOutput());
        return is_numeric($out) ? (float) $out : 0.0;
    }

    protected function isPlayableVideo(string $filePath): bool
    {
        if (!file_exists($filePath) || @filesize($filePath) < 65536) {
            return false;
        }

        $ffprobe = $this->findBinary('ffprobe');
        if (!$ffprobe) {
            return true;
        }

        $codecs = $this->probeCodecs($filePath);
        return $codecs['video'] !== '';
    }

    protected function findBinary(string $name): ?string
    {
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $proc = new Process([$finder, $name]);
        $proc->setTimeout(5);
        $proc->run();

        if ($proc->isSuccessful()) {
            $lines = preg_split('/\r?\n/', trim($proc->getOutput())) ?: [];
            $path = trim((string) ($lines[0] ?? ''));
            if ($path !== '') {
                $cache[$name] = $path;
                return $path;
            }
        }

        foreach (["/usr/bin/{$name}", "/usr/local/bin/{$name}"] as $common) {
            if (is_executable($common)) {
                $cache[$name] = $common;
                return $common;
            }
        }

        $cache[$name] = null;
        return null;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int) floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        return number_format($bytes / (1024 ** $pow), $pow >= 2 ? 1 : 0) . ' ' . $units[$pow];
    }

    protected function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}
