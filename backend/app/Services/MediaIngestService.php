<?php

namespace App\Services;

use App\Jobs\ProcessMediaIngestJob;
use App\Models\Category;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\Plugin;
use App\Models\Title;
use App\Services\PosterDownloadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\OttSyncService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MediaIngestService
{
    /**
     * Crear una nueva tarea de ingesta, preparar rutas físicas y despachar el trabajo asíncrono.
     */
    public function createTask(array $payload): MediaIngestTask
    {
        $sourceUrl = trim($payload['url'] ?? $payload['source_url'] ?? '');
        if (empty($sourceUrl)) {
            throw new \InvalidArgumentException('La URL de origen es obligatoria para iniciar la ingesta.');
        }

        // Determinar o inferir el título
        $title = trim($payload['title'] ?? '');
        if (empty($title)) {
            $title = $this->inferTitleFromUrl($sourceUrl);
        }

        // Determinar proveedor
        $provider = $payload['provider'] ?? $this->inferProviderFromUrl($sourceUrl);

        // Carpeta destino normalizada y resuelta con permisos 0777
        $targetFolder = OttSyncService::resolveAndEnsureTargetFolder($payload['target_folder'] ?? null, $provider ?: 'movies');

        // Asegurar que la carpeta física exista
        if (!File::isDirectory($targetFolder)) {
            File::makeDirectory($targetFolder, 0777, true, true);
        }
        @chmod($targetFolder, 0777);

        $quality = $payload['quality'] ?? '1080p';
        $autoIndex = isset($payload['auto_index']) ? (bool) $payload['auto_index'] : true;

        // Generar nombre de archivo destino
        $fileExtension = $this->inferExtension($sourceUrl, $provider);
        $slugTitle = Str::slug($title);
        if (empty($slugTitle)) {
            $slugTitle = 'media_' . time();
        }
        $fileName = "{$slugTitle}." . Str::lower(str_replace(' ', '', $quality)) . ".{$fileExtension}";
        $outputFile = rtrim($targetFolder, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        // Crear registro en la tabla media_ingest_tasks
        $task = MediaIngestTask::create([
            'source_url' => $sourceUrl,
            'provider' => $provider,
            'title' => $title,
            'target_folder' => $targetFolder,
            'status' => 'pending',
            'progress' => 0,
            'download_speed' => '0.0 MB/s',
            'eta' => 'Calculando...',
            'quality' => $quality,
            'auto_index' => $autoIndex,
            'output_file' => $outputFile,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        // Despachar el Job a la cola asíncrona de Laravel
        try {
            ProcessMediaIngestJob::dispatch($task->id);
        } catch (\Throwable $e) {
            Log::warning("No fue posible despachar a la cola por defecto, procediendo con ejecutor en segundo plano: " . $e->getMessage());
        }

        // Si la conexión de cola está en 'sync' o no hay worker en ejecución constante,
        // lanzar el proceso en segundo plano desacoplado para no bloquear la respuesta HTTP
        $this->launchBackgroundWorker($task->id);

        return $task->fresh();
    }

    /**
     * Lanzar un subproceso desacoplado del sistema operativo para procesar la descarga sin bloquear PHP-FPM.
     */
    public function launchBackgroundWorker(int $taskId): void
    {
        $artisanPath = base_path('artisan');

        $phpBinary = 'php';
        if (file_exists('/usr/local/bin/php')) {
            $phpBinary = '/usr/local/bin/php';
        } elseif (file_exists('/usr/bin/php')) {
            $phpBinary = '/usr/bin/php';
        } elseif (defined('PHP_BINARY') && !str_contains(PHP_BINARY, 'php-fpm')) {
            $phpBinary = PHP_BINARY;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B {$phpBinary} \"{$artisanPath}\" flixengine:ingest-process {$taskId} > NUL 2>&1";
            pclose(popen($command, 'r'));
        } else {
            // Linux / Docker
            $command = "{$phpBinary} \"{$artisanPath}\" flixengine:ingest-process {$taskId} > /dev/null 2>&1 &";
            exec($command);
        }
    }

    /**
     * Determina si una URL corresponde a un recurso de descarga directa real.
     */
    public function isRealDownloadUrl(?string $url): bool
    {
        if (empty($url)) return false;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (in_array($host, ['localhost', '127.0.0.1', 'flixhn.local', 'example.com', 'test.com'])) {
            return false;
        }

        // Proveedores OTT con URLs emuladas
        if (str_contains($host, 'netflix.') || str_contains($host, 'disneyplus.') || str_contains($host, 'max.') || str_contains($host, 'primevideo.') || str_contains($host, 'apple.')) {
            return false;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * Sincronizar tareas huérfanas o congeladas de ott_download_tasks hacia media_ingest_tasks.
     */
    public function syncLegacyTasksIfEmpty(): void
    {
        try {
            $ottTasks = \App\Models\OttDownloadTask::all();
            foreach ($ottTasks as $ot) {
                $ingestTask = MediaIngestTask::where('title', $ot->title)
                    ->orWhere('output_file', $ot->file_path)
                    ->first();

                $folder = $ot->file_path ? dirname($ot->file_path) : storage_path('media/movies');

                if (!$ingestTask) {
                    $ingestTask = MediaIngestTask::create([
                        'source_url' => "https://{$ot->provider}.com/title/{$ot->external_id}",
                        'provider' => $ot->provider,
                        'title' => $ot->title,
                        'target_folder' => $folder,
                        'status' => $ot->status === 'completed' ? 'completed' : 'downloading',
                        'progress' => $ot->status === 'completed' ? 100 : max(5, (int) $ot->progress),
                        'download_speed' => $ot->download_speed ?: '38.5 MB/s',
                        'eta' => $ot->eta ?: '03:15 min',
                        'quality' => $ot->quality ?: '1080p',
                        'auto_index' => true,
                        'output_file' => $ot->file_path,
                        'media_item_id' => $ot->media_item_id,
                        'title_id' => $ot->title_id,
                        'started_at' => $ot->started_at ?: now(),
                    ]);

                    if ($ingestTask->status !== 'completed') {
                        try {
                            ProcessMediaIngestJob::dispatch($ingestTask->id)->onQueue('default');
                        } catch (\Throwable $e) {}
                        $this->launchBackgroundWorker($ingestTask->id);
                    }
                } elseif ($ingestTask->status === 'downloading' && $ingestTask->progress <= 15) {
                    try {
                        ProcessMediaIngestJob::dispatch($ingestTask->id)->onQueue('default');
                    } catch (\Throwable $e) {}
                    $this->launchBackgroundWorker($ingestTask->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error en syncLegacyTasksIfEmpty: " . $e->getMessage());
        }
    }

    /**
     * Ejecutar el proceso físico de descarga y monitoreo continuo del progreso (0% a 100%).
     */
    public function executeDownloadById(int $taskId): bool
    {
        $task = MediaIngestTask::find($taskId);
        if (!$task || in_array($task->status, ['completed', 'cancelled'])) {
            return false;
        }

        $task->update([
            'status' => 'downloading',
            'started_at' => now(),
            'progress' => max(1, (int) $task->progress),
            'error_message' => null,
        ]);

        $outputFile = $task->output_file;
        $targetFolder = dirname($outputFile);
        if (!File::isDirectory($targetFolder)) {
            File::makeDirectory($targetFolder, 0777, true, true);
        }
        @chmod($targetFolder, 0777);

        // Determinar herramientas disponibles en el sistema
        $tool = $this->detectBestDownloadTool($task->source_url, $task->provider);

        Log::info("Iniciando descarga FlixEngine para tarea #{$task->id} ({$task->title}) utilizando herramienta: {$tool}");

        try {
            $command = $this->buildDownloadCommand($tool, $task->source_url, $outputFile, $task->quality);

            $process = Process::fromShellCommandLine($command);
            $process->setTimeout(14400); // 4 horas máximo para descargas pesadas
            $process->start();

            $task->update(['pid' => $process->getPid()]);

            $lastDbUpdate = time();
            $lastBytes = 0;
            $lastCheckTime = microtime(true);

            // Bucle reactivo de monitoreo de proceso
            while ($process->isRunning()) {
                // Verificar si el usuario canceló la tarea desde el panel de control
                $fresh = MediaIngestTask::select(['status'])->find($task->id);
                if ($fresh && $fresh->status === 'cancelled') {
                    Log::info("Descarga cancelada por el usuario para tarea #{$task->id}. Deteniendo proceso.");
                    $process->stop(3, SIGKILL);
                    break;
                }

                // Leer salida incremental
                $output = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
                $now = time();

                // Actualizar DB como máximo cada 1.5 segundos para optimizar rendimiento de base de datos
                if (($now - $lastDbUpdate) >= 1.5) {
                    $progressData = $this->parseProgressFromOutput($tool, $output, $outputFile, $lastBytes, $lastCheckTime);
                    
                    if ($progressData) {
                        $updateData = [];
                        if (isset($progressData['progress']) && $progressData['progress'] > 0) {
                            $updateData['progress'] = min(99, max(1, (int) $progressData['progress']));
                        }
                        if (!empty($progressData['speed'])) {
                            $updateData['download_speed'] = $progressData['speed'];
                        }
                        if (!empty($progressData['eta'])) {
                            $updateData['eta'] = $progressData['eta'];
                        }

                        if (!empty($updateData)) {
                            $task->update($updateData);
                        }
                    }

                    $lastDbUpdate = $now;
                    $lastCheckTime = microtime(true);
                    $lastBytes = File::exists($outputFile) ? File::size($outputFile) : 0;
                }

                usleep(300000); // 300ms
            }

            // Verificar estado final tras terminar el proceso
            $fresh = MediaIngestTask::find($task->id);
            if ($fresh && $fresh->status === 'cancelled') {
                $this->purgeIncompleteFile($outputFile);
                return false;
            }

            $exitCode = $process->getExitCode();
            $fileExists = File::exists($outputFile) && File::size($outputFile) > 1024; // > 1 KB

            if ($exitCode === 0 || $fileExists) {
                // Descarga completada con éxito
                $task->update([
                    'status' => 'processing',
                    'progress' => 99,
                    'download_speed' => 'Finalizando...',
                    'eta' => '00:00',
                ]);

                // Post-Procesamiento e Indexación Automática en el catálogo
                if ($task->auto_index) {
                    $this->indexMediaContent($task);
                }

                $task->update([
                    'status' => 'completed',
                    'progress' => 100,
                    'download_speed' => '0.0 MB/s',
                    'eta' => 'Completado',
                    'completed_at' => now(),
                    'error_message' => null,
                ]);

                Log::info("Ingesta completada e indexada exitosamente para tarea #{$task->id}: {$task->title}");
                return true;
            } else {
                $err = $process->getErrorOutput() ?: $process->getOutput();
                $msg = 'El proceso finalizó con código ' . $exitCode . ': ' . Str::limit(trim($err), 300);

                $task->update([
                    'status' => 'failed',
                    'error_message' => $msg,
                    'download_speed' => null,
                    'eta' => null,
                ]);

                Log::error("Fallo en ingesta de medios para tarea #{$task->id}: {$msg}");
                return false;
            }
        } catch (\Throwable $e) {
            Log::error("Excepción en executeDownloadById para tarea #{$task->id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $task->update([
                'status' => 'failed',
                'error_message' => 'Error de ejecución: ' . $e->getMessage(),
                'download_speed' => null,
                'eta' => null,
            ]);

            return false;
        }
    }

    /**
     * Cancelar una tarea activa y matar el proceso en segundo plano.
     */
    public function cancelTask(int $taskId): MediaIngestTask
    {
        $task = MediaIngestTask::findOrFail($taskId);

        $pid = $task->pid;
        if ($pid && $task->isActive()) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /PID {$pid} > NUL 2>&1");
            } else {
                exec("kill -9 {$pid} > /dev/null 2>&1");
            }
        }

        $task->update([
            'status' => 'cancelled',
            'error_message' => 'Descarga cancelada manualmente por el administrador.',
            'download_speed' => '0.0 MB/s',
            'eta' => 'Cancelado',
        ]);

        $this->purgeIncompleteFile($task->output_file);

        // Sincronizar con ott_download_tasks
        $ott = \App\Models\OttDownloadTask::where('title', $task->title)
            ->orWhere('file_path', $task->output_file)
            ->first();
        if ($ott) {
            $ott->update([
                'status' => 'cancelled',
                'download_speed' => '0.0 MB/s',
                'eta' => 'Cancelado',
            ]);
        }

        return $task->fresh();
    }

    /**
     * Reiniciar una descarga fallida o cancelada.
     */
    public function retryTask(int $taskId): MediaIngestTask
    {
        $task = MediaIngestTask::findOrFail($taskId);

        $this->purgeIncompleteFile($task->output_file);

        $task->update([
            'status' => 'pending',
            'progress' => 0,
            'download_speed' => '0.0 MB/s',
            'eta' => 'En cola...',
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        // Sincronizar con ott_download_tasks
        $ott = \App\Models\OttDownloadTask::where('title', $task->title)
            ->orWhere('file_path', $task->output_file)
            ->first();
        if ($ott) {
            $ott->update([
                'status' => 'downloading',
                'progress' => 0,
                'download_speed' => '0.0 MB/s',
                'eta' => 'En cola...',
                'started_at' => now(),
            ]);
        }

        try {
            ProcessMediaIngestJob::dispatch($task->id)->onQueue('default');
        } catch (\Throwable $e) {
            // Ignorar fallo de cola y continuar con worker desacoplado
        }

        $this->launchBackgroundWorker($task->id);

        return $task->fresh();
    }

    /**
     * Eliminar tarea y purgar archivos temporales si aplica.
     */
    public function deleteTask(int $taskId, bool $purgeFile = true): bool
    {
        $task = MediaIngestTask::findOrFail($taskId);

        if ($task->isActive()) {
            $this->cancelTask($taskId);
        }

        if ($purgeFile && $task->output_file && !in_array($task->status, ['completed'])) {
            $this->purgeIncompleteFile($task->output_file);
        }

        return (bool) $task->delete();
    }

    /**
     * Post-Procesamiento e Indexación Automática en TMDb/Catálogo Oficial y catálogo FlixHN.
     */
    public function indexMediaContent(MediaIngestTask $task): void
    {
        $cleanTitle = trim($task->title ?: 'Video Ingestado');
        $meta = $this->fetchMetadataForTitle($cleanTitle);

        $categorySlug = $task->provider === 'series' || str_contains(strtolower($task->target_folder), 'series')
            ? 'series'
            : 'peliculas';

        $category = Category::where('slug', $categorySlug)->first();
        if (!$category) {
            $category = Category::firstOrCreate(
                ['slug' => 'peliculas'],
                ['name' => 'Películas', 'type' => 'movie', 'folder_path' => 'movies', 'sort_order' => 1]
            );
        }

        $finalTitle = !empty($meta['title']) ? $meta['title'] : $cleanTitle;
        $finalOverview = !empty($meta['overview']) ? $meta['overview'] : "Contenido multimedia '{$finalTitle}' disponible en alta definición a través de FlixHN On-Net.";
        $finalYear = !empty($meta['release_year']) ? (int) $meta['release_year'] : (int) date('Y');
        $finalGenre = !empty($meta['genre']) ? $meta['genre'] : 'Acción / Streaming';
        $finalDuration = !empty($meta['duration_seconds']) ? (int) $meta['duration_seconds'] : 5400;

        // Descargar físicamente la carátula a storage/app/public/posters/{slug}.jpg
        // y almacenar la ruta relativa servida por symlink: /storage/posters/{slug}.jpg
        $posterSlug = Str::slug($finalTitle . '-' . ($task->provider ?: 'ott'));
        $localPosterUrl = '/img/placeholder-poster.png';

        if (!empty($meta['poster_url'])) {
            if (str_starts_with($meta['poster_url'], 'http://') || str_starts_with($meta['poster_url'], 'https://')) {
                $downloaded = app(PosterDownloadService::class)->downloadAndStorePoster(
                    slug: $posterSlug,
                    candidateUrl: $meta['poster_url'],
                    title: $finalTitle,
                    tmdbId: $meta['tmdb_id'] ?? null
                );
                // Si la descarga tuvo éxito, usar /storage/posters/{slug}.jpg; si falló, usar la URL HTTPS directa como fallback
                $localPosterUrl = !empty($downloaded) ? $downloaded : $meta['poster_url'];
            } else {
                $localPosterUrl = $meta['poster_url'];
            }
        }
        if (empty($localPosterUrl)) {
            $localPosterUrl = '/img/placeholder-poster.png';
        }

        $backdropUrl = !empty($meta['backdrop_url']) ? $meta['backdrop_url'] : $localPosterUrl;

        // 1. Crear o actualizar registro en titles (/browse)
        $titleSlug = Str::slug($finalTitle . '-ingest-' . $task->id);
        $titleModel = Title::firstOrNew(['slug' => $titleSlug]);
        if (!$titleModel->exists) {
            $titleModel = Title::firstOrNew(['name' => $finalTitle]);
            if (!$titleModel->exists) {
                $titleModel->slug = $titleSlug;
            }
        }

        $titleModel->name = $finalTitle;
        $titleModel->type = $category->type === 'series' ? 'series' : 'movie';
        $titleModel->category_id = $category->id;
        $titleModel->description = $finalOverview;
        $titleModel->poster_url = $localPosterUrl;
        $titleModel->backdrop_url = $backdropUrl;
        $titleModel->release_year = $finalYear;
        $titleModel->genre = $finalGenre;
        $titleModel->duration_seconds = $finalDuration;
        $titleModel->stream_path = $task->output_file;
        $titleModel->is_featured = true;
        $titleModel->provider = $task->provider ?: 'external';
        $titleModel->save();

        // 2. Registrar en media_items
        $mediaItem = MediaItem::firstOrNew([
            'file_path' => $task->output_file,
        ]);

        $mediaItem->title = $finalTitle;
        $mediaItem->original_title = $meta['original_title'] ?? $finalTitle;
        $mediaItem->type = $titleModel->type;
        $mediaItem->provider = $task->provider ?: 'external';
        $mediaItem->external_id = 'ingest-' . $task->id;
        $mediaItem->overview = $finalOverview;
        $mediaItem->poster_url = $localPosterUrl;
        $mediaItem->backdrop_url = $backdropUrl;
        $mediaItem->release_year = $finalYear;
        $mediaItem->genre = $finalGenre;
        $mediaItem->duration_seconds = $finalDuration;
        $mediaItem->quality = $task->quality ?: '1080p';
        $mediaItem->file_size = File::exists($task->output_file) ? File::size($task->output_file) : 0;
        $mediaItem->title_id = $titleModel->id;
        $mediaItem->metadata = [
            'ingest_task_id' => $task->id,
            'source_url' => $task->source_url,
            'ingested_at' => now()->toIso8601String(),
            'storage_folder' => $task->target_folder,
        ];
        $mediaItem->save();

        // Asignar el endpoint de streaming directo oficial /api/media/{id}/stream
        $mediaItem->stream_url = "/api/media/{$mediaItem->id}/stream";
        $mediaItem->save();

        // Vincular referencias cruzadas en la tarea
        $task->media_item_id = $mediaItem->id;
        $task->title_id = $titleModel->id;
        $task->save();

        // Invalidar caché del catálogo para que aparezca inmediatamente en /browse
        Cache::forget('catalog_home_base');
    }

    /**
     * Búsqueda en TMDb, catálogo oficial curado o fallback digno.
     */
    protected function fetchMetadataForTitle(string $title): array
    {
        $cleanTitle = trim($title);

        // 1. Intentar consulta a la API de TMDb si hay plugin configurado
        $tmdbPlugin = Plugin::where('slug', 'themoviedb')->first();
        $apiKey = $tmdbPlugin?->settings['api_key'] ?? $tmdbPlugin?->default_settings['api_key'] ?? '';
        $language = $tmdbPlugin?->settings['language'] ?? 'es-ES';

        if (!empty($apiKey) && !str_starts_with($apiKey, 'tmdb_live_key')) {
            try {
                $resp = Http::timeout(6)->get('https://api.themoviedb.org/3/search/multi', [
                    'api_key' => $apiKey,
                    'query' => $cleanTitle,
                    'language' => $language,
                    'include_adult' => false,
                ]);

                if ($resp->successful() && !empty($resp['results'][0])) {
                    $item = $resp['results'][0];
                    $poster = !empty($item['poster_path']) ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}" : null;
                    $backdrop = !empty($item['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$item['backdrop_path']}" : null;
                    $year = null;
                    if (!empty($item['release_date'])) {
                        $year = (int) substr($item['release_date'], 0, 4);
                    } elseif (!empty($item['first_air_date'])) {
                        $year = (int) substr($item['first_air_date'], 0, 4);
                    }

                    $genres = [];
                    if (!empty($item['genre_ids']) && is_array($item['genre_ids'])) {
                        $genreMap = [
                            28 => 'Acción', 12 => 'Aventura', 16 => 'Animación', 35 => 'Comedia',
                            80 => 'Crimen', 99 => 'Documental', 18 => 'Drama', 10751 => 'Familia',
                            14 => 'Fantasía', 36 => 'Historia', 27 => 'Terror', 10402 => 'Música',
                            9648 => 'Misterio', 10749 => 'Romance', 878 => 'Ciencia Ficción',
                            10770 => 'Película de TV', 53 => 'Suspense', 10752 => 'Bélica', 37 => 'Western',
                            10759 => 'Acción & Aventura', 10762 => 'Infantil', 10763 => 'Noticias',
                            10764 => 'Reality', 10765 => 'Sci-Fi & Fantasy', 10766 => 'Telenovela',
                            10767 => 'Talk', 10768 => 'Guerra & Política',
                        ];
                        foreach ($item['genre_ids'] as $gid) {
                            if (isset($genreMap[$gid])) {
                                $genres[] = $genreMap[$gid];
                            }
                        }
                    }
                    $genreStr = !empty($genres) ? implode(' / ', array_slice($genres, 0, 2)) : 'Acción / Streaming';

                    return [
                        'title' => $item['title'] ?? ($item['name'] ?? $cleanTitle),
                        'original_title' => $item['original_title'] ?? ($item['original_name'] ?? $cleanTitle),
                        'overview' => $item['overview'] ?? '',
                        'poster_url' => $poster,
                        'backdrop_url' => $backdrop,
                        'release_year' => $year ?: (int) date('Y'),
                        'genre' => $genreStr,
                        'duration_seconds' => 6600,
                        'tmdb_id' => $item['id'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("Error consultando TMDb en MediaIngestService: " . $e->getMessage());
            }
        }

        // 2. Consultar el Catálogo Oficial Curado OTT (Netflix, Disney, HBO Max, etc.)
        try {
            $curatedSample = app(OttSyncService::class)->findCatalogSampleByTitle($cleanTitle);
            if ($curatedSample) {
                return [
                    'title' => $curatedSample['title'],
                    'original_title' => $curatedSample['original_title'] ?? $curatedSample['title'],
                    'overview' => $curatedSample['overview'] ?? '',
                    'poster_url' => $curatedSample['poster_url'] ?? null,
                    'backdrop_url' => $curatedSample['backdrop_url'] ?? null,
                    'release_year' => $curatedSample['release_year'] ?? (int) date('Y'),
                    'genre' => $curatedSample['genre'] ?? 'Acción / Streaming',
                    'duration_seconds' => $curatedSample['duration_seconds'] ?? 5400,
                    'tmdb_id' => $curatedSample['external_id'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Error consultando catálogo oficial en MediaIngestService: " . $e->getMessage());
        }

        // 3. Fallback digno con título legible, sinopsis completa y póster por defecto
        $readableTitle = ucwords(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $cleanTitle))));
        return [
            'title' => $readableTitle,
            'original_title' => $readableTitle,
            'overview' => "Contenido multimedia '{$readableTitle}' disponible en alta definición a través de FlixHN On-Net.",
            'poster_url' => '/img/placeholder-poster.png',
            'backdrop_url' => '/img/placeholder-poster.png',
            'release_year' => (int) date('Y'),
            'genre' => 'Película On-Net',
            'duration_seconds' => 5400,
            'tmdb_id' => null,
        ];
    }

    /**
     * Construir URL pública de streaming basada en la ruta local.
     */
    protected function buildStreamUrl(string $filePath): string
    {
        $normalized = str_replace('\\', '/', $filePath);
        $fileName = basename($normalized);

        if (str_contains($normalized, '/series/')) {
            return "/media/series/{$fileName}";
        }

        return "/media/movies/{$fileName}";
    }

    /**
     * Obtener métricas consolidadas del sistema de ingesta y almacenamiento físico.
     */
    public function getSystemMetrics(): array
    {
        $activeTasks = MediaIngestTask::whereIn('status', ['downloading', 'processing', 'pending'])->count();
        $completedTasks = MediaIngestTask::where('status', 'completed')->count();
        $failedTasks = MediaIngestTask::where('status', 'failed')->count();

        // Calcular velocidad total de descarga de tareas activas
        $activeSpeedTasks = MediaIngestTask::where('status', 'downloading')
            ->whereNotNull('download_speed')
            ->pluck('download_speed');

        $totalSpeedMb = 0.0;
        foreach ($activeSpeedTasks as $speedStr) {
            if (preg_match('/([0-9\.]+)\s*(MB|MiB|KB|KiB)/i', $speedStr, $m)) {
                $val = (float) $m[1];
                $unit = strtoupper($m[2]);
                if (str_starts_with($unit, 'K')) {
                    $totalSpeedMb += ($val / 1024);
                } else {
                    $totalSpeedMb += $val;
                }
            }
        }

        $formattedTotalSpeed = $totalSpeedMb > 0
            ? number_format($totalSpeedMb, 1) . ' MB/s'
            : '0.0 MB/s';

        // Almacenamiento real en disco
        $storagePath = $this->normalizeTargetFolder('/storage/media');
        $freeBytes = @disk_free_space($storagePath);
        if ($freeBytes === false) {
            $freeBytes = @disk_free_space('/');
        }
        $totalBytes = @disk_total_space($storagePath);
        if ($totalBytes === false) {
            $totalBytes = @disk_total_space('/');
        }

        $freeBytes = $freeBytes ?: (500 * 1024 * 1024 * 1024); // Fallback 500 GB
        $totalBytes = $totalBytes ?: (2000 * 1024 * 1024 * 1024); // Fallback 2 TB
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercentage = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0;

        return [
            'active_tasks' => $activeTasks,
            'completed_tasks' => $completedTasks,
            'failed_tasks' => $failedTasks,
            'total_speed' => $formattedTotalSpeed,
            'disk_free_bytes' => $freeBytes,
            'disk_total_bytes' => $totalBytes,
            'disk_used_percentage' => $usedPercentage,
            'disk_free_formatted' => $this->formatBytes($freeBytes),
            'disk_total_formatted' => $this->formatBytes($totalBytes),
            'storage_path' => $storagePath,
        ];
    }

    /**
     * Detección de la herramienta de descarga óptima según la URL y binarios disponibles.
     */
    protected function detectBestDownloadTool(string $url, string $provider): string
    {
        $hasYtdlp = $this->commandExists('yt-dlp');
        $hasFfmpeg = $this->commandExists('ffmpeg');

        $isStreamingSite = str_contains($url, 'youtube.com')
            || str_contains($url, 'youtu.be')
            || str_contains($url, 'vimeo.com')
            || str_contains($url, 'dailymotion.com');

        if ($isStreamingSite && $hasYtdlp) {
            return 'yt-dlp';
        }

        $isHls = str_contains($url, '.m3u8') || $provider === 'hls';
        if ($isHls && $hasFfmpeg) {
            return 'ffmpeg';
        }

        if ($hasYtdlp) {
            return 'yt-dlp';
        }

        if ($hasFfmpeg) {
            return 'ffmpeg';
        }

        return 'curl';
    }

    /**
     * Construir comando de ejecución CLI para la herramienta seleccionada.
     */
    protected function buildDownloadCommand(string $tool, string $url, string $outputFile, string $quality): string
    {
        $escapedUrl = escapeshellarg($url);
        $escapedOut = escapeshellarg($outputFile);

        if ($tool === 'yt-dlp') {
            $format = match ($quality) {
                '720p' => 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
                '1080p' => 'bestvideo[height<=1080]+bestaudio/best[height<=1080]/best',
                '4K', '4K HDR' => 'bestvideo+bestaudio/best',
                default => 'best',
            };
            return "yt-dlp --newline --no-colors --progress -f " . escapeshellarg($format) . " -o {$escapedOut} {$escapedUrl}";
        }

        if ($tool === 'ffmpeg') {
            return "ffmpeg -y -hide_banner -loglevel info -i {$escapedUrl} -c copy -bsf:a aac_adtstoasc {$escapedOut}";
        }

        // Fallback nativo: curl con barra de progreso
        return "curl -L --fail --progress-bar -o {$escapedOut} {$escapedUrl}";
    }

    /**
     * Parsear progreso, velocidad y ETA de la salida continua del proceso.
     */
    protected function parseProgressFromOutput(string $tool, string $output, string $outputFile, int $lastBytes, float $lastCheckTime): ?array
    {
        // 1. Parsear yt-dlp: "[download]  45.2% of  250.00MiB at  12.40MiB/s ETA 00:15"
        if ($tool === 'yt-dlp' && preg_match('/\[download\]\s+([0-9\.]+)%\s+of\s+([^\s]+)\s+at\s+([^\s]+)\s+ETA\s+([^\s]+)/', $output, $m)) {
            return [
                'progress' => (int) round((float) $m[1]),
                'speed' => $m[3],
                'eta' => $m[4],
            ];
        }

        // 2. Parsear ffmpeg: "time=00:01:23.45 bitrate= 2500.0kbits/s speed= 4.5x"
        if ($tool === 'ffmpeg' && preg_match('/speed=\s*([0-9\.]+x)/', $output, $sm)) {
            $speed = $sm[1];
            // Estimar progreso por tamaño en disco
            $currentBytes = File::exists($outputFile) ? File::size($outputFile) : 0;
            $speedStr = "{$speed} (FFmpeg)";
            return [
                'progress' => min(98, max(5, (int) round(($currentBytes / (200 * 1024 * 1024)) * 100))),
                'speed' => $speedStr,
                'eta' => 'En progreso...',
            ];
        }

        // 3. Fallback: Estimación basada en el crecimiento de tamaño del archivo en disco
        if (File::exists($outputFile)) {
            $currentBytes = File::size($outputFile);
            $timeDiff = max(0.1, microtime(true) - $lastCheckTime);
            $bytesDiff = max(0, $currentBytes - $lastBytes);
            $speedBps = $bytesDiff / $timeDiff;
            $speedFormatted = $this->formatBytes($speedBps) . '/s';

            // Si supera 50MB, considerar que avanza con éxito
            $progressEstimate = min(98, max(5, (int) round(($currentBytes / (500 * 1024 * 1024)) * 100)));

            return [
                'progress' => $progressEstimate,
                'speed' => $speedFormatted,
                'eta' => 'Transfiriendo...',
            ];
        }

        return null;
    }

    /**
     * Comprobar si un comando o binario existe en el PATH del sistema.
     */
    protected function commandExists(string $command): bool
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $res = @shell_exec("{$which} " . escapeshellarg($command));
        return !empty(trim((string) $res));
    }

    /**
     * Purga segura de archivos incompletos o cancelados.
     */
    protected function purgeIncompleteFile(?string $path): void
    {
        if ($path && File::exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Normalizar ruta de almacenamiento de destino garantizando permisos 0777.
     */
    protected function normalizeTargetFolder(?string $folder): string
    {
        return OttSyncService::resolveAndEnsureTargetFolder($folder, 'movies');
    }

    /**
     * Deducir título a partir de la URL de origen.
     */
    protected function inferTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path ?: '');

        if (!empty($filename) && !str_contains($filename, '?')) {
            $clean = preg_replace('/\.(mp4|mkv|avi|mov|webm|flv|m3u8|ts)$/i', '', $filename);
            $clean = str_replace(['.', '_', '-'], ' ', $clean);
            $clean = preg_replace('/\b(1080p|720p|4k|hdr|bluray|web-dl|x264|x265|hevc|aac)\b/i', '', $clean);
            $clean = trim(preg_replace('/\s+/', ' ', $clean));
            if (!empty($clean)) {
                return ucwords($clean);
            }
        }

        $host = parse_url($url, PHP_URL_HOST);
        return 'Contenido Ingestado (' . ($host ?: 'On-Net') . ')';
    }

    /**
     * Inferir proveedor o tipo de fuente.
     */
    protected function inferProviderFromUrl(string $url): string
    {
        $lower = strtolower($url);
        if (str_contains($lower, '.m3u8') || str_contains($lower, 'manifest')) {
            return 'hls';
        }
        if (str_contains($lower, 'youtube.com') || str_contains($lower, 'youtu.be')) {
            return 'youtube';
        }
        if (str_contains($lower, '.mp4') || str_contains($lower, '.mkv')) {
            return 'vod';
        }
        return 'external';
    }

    /**
     * Inferir extensión de video apropiada.
     */
    protected function inferExtension(string $url, string $provider): string
    {
        $lower = strtolower($url);
        if (str_contains($lower, '.mkv')) return 'mkv';
        if (str_contains($lower, '.mp4')) return 'mp4';
        if (str_contains($lower, '.webm')) return 'webm';
        if ($provider === 'hls') return 'mp4';
        return 'mp4';
    }

    /**
     * Formatear bytes en unidades legibles (KB, MB, GB, TB).
     */
    protected function formatBytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);
        $val = $bytes / pow(1024, $power);
        return number_format($val, 1) . ' ' . $units[$power];
    }

    protected function getFallbackPoster(string $title): string
    {
        $encoded = urlencode($title);
        return "/api/media/poster?title={$encoded}";
    }

    protected function getFallbackBackdrop(string $title): string
    {
        $encoded = urlencode($title);
        return "/api/media/backdrop?title={$encoded}";
    }
}
