<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MediaIngestTask;
use App\Models\MediaItem;
use App\Models\OttDownloadTask;
use App\Models\OttProviderAccount;
use App\Models\Title;
use App\Services\MediaIngestService;
use App\Services\OttSyncService;
use App\Services\PosterDownloadService;
use App\Services\RemoteMediaConnectorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlixGrabDaemonService
{
    protected string $pidFile;
    protected string $logFile;

    public function __construct(
        protected MediaIngestService $ingestService,
        protected OttSyncService $ottSyncService,
        protected PosterDownloadService $posterService
    ) {
        $this->pidFile = storage_path('app/flixgrab_daemon.pid');
        $this->logFile = storage_path('logs/flixgrab_daemon.log');
    }

    /**
     * Obtiene el estado actual del daemon (activo/detenido, PID, tareas, proveedores).
     */
    public function getStatus(): array
    {
        $pid = null;
        $isRunning = false;

        $pidFiles = [
            $this->pidFile,
            base_path('daemon/flixgrab_daemon.pid'),
            base_path('../daemon/flixgrab_daemon.pid'),
            '/var/www/html/daemon/flixgrab_daemon.pid',
        ];

        foreach ($pidFiles as $candidate) {
            if (File::exists($candidate)) {
                $rawPid = trim((string) File::get($candidate));
                if (is_numeric($rawPid)) {
                    $candidatePid = (int) $rawPid;
                    if ($this->isPidRunning($candidatePid)) {
                        $pid = $candidatePid;
                        $isRunning = true;
                        break;
                    } else {
                        // Limpiar PID huérfano si el proceso ya no existe
                        @unlink($candidate);
                    }
                }
            }
        }

        $activeTasks = OttDownloadTask::whereIn('status', ['pending', 'downloading'])->count();
        $completedTasks = OttDownloadTask::where('status', 'completed')->count();
        $connectedProviders = OttProviderAccount::where('status', 'connected')->pluck('provider')->toArray();

        return [
            'name' => 'FlixGrab OTT Engine Daemon',
            'running' => $isRunning,
            'pid' => $isRunning ? $pid : null,
            'status_label' => $isRunning ? 'ACTIVO (En segundo plano)' : 'DETENIDO',
            'active_tasks' => $activeTasks,
            'completed_tasks' => $completedTasks,
            'connected_providers' => $connectedProviders,
            'last_check' => now()->toIso8601String(),
        ];
    }

    /**
     * Inicia el daemon en segundo plano desacoplado de PHP-FPM / HTTP.
     */
    public function startDaemon(): array
    {
        $status = $this->getStatus();
        if ($status['running']) {
            return $status;
        }

        $artisanPath = base_path('artisan');
        $phpBinary = $this->resolvePhpBinary();

        // Asegurar directorio storage/app y storage/logs
        if (!File::isDirectory(storage_path('app'))) {
            File::makeDirectory(storage_path('app'), 0777, true, true);
        }
        if (!File::isDirectory(storage_path('logs'))) {
            File::makeDirectory(storage_path('logs'), 0777, true, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B {$phpBinary} \"{$artisanPath}\" flixgrab:daemon run > NUL 2>&1";
            pclose(popen($command, 'r'));
        } else {
            // Linux / Docker Alpine
            $logPath = escapeshellarg($this->logFile);
            $command = "nohup {$phpBinary} \"{$artisanPath}\" flixgrab:daemon run >> {$logPath} 2>&1 &";
            exec($command);
        }

        // Esperar activamente a que el proceso levante y cree el archivo PID (hasta 1.5s)
        for ($i = 0; $i < 15; $i++) {
            usleep(100000); // 100ms
            if (File::exists($this->pidFile)) {
                break;
            }
        }

        return $this->getStatus();
    }

    /**
     * Detiene el daemon en ejecución enviando señal de terminación al PID.
     */
    public function stopDaemon(): array
    {
        $status = $this->getStatus();
        if ($status['running'] && $status['pid']) {
            $pid = $status['pid'];
            Log::info("Deteniendo FlixGrab Daemon con PID {$pid}");

            if (PHP_OS_FAMILY === 'Windows') {
                @exec("taskkill /F /PID {$pid} 2>&1");
            } else {
                @exec("kill -15 {$pid} 2>/dev/null");
                usleep(500000);
                if ($this->isPidRunning($pid)) {
                    @exec("kill -9 {$pid} 2>/dev/null");
                }
            }
        }

        @unlink($this->pidFile);
        @unlink(base_path('daemon/flixgrab_daemon.pid'));
        @unlink(base_path('../daemon/flixgrab_daemon.pid'));

        return $this->getStatus();
    }

    /**
     * Ejecuta una sola iteración inmediata del daemon (comprobar cuentas, autodescarga y procesar descargas).
     */
    public function runOnce(): array
    {
        $this->processIteration();
        return $this->getStatus();
    }

    /**
     * Bucle continuo del daemon (para ejecución en CLI / background worker).
     */
    public function runDaemonLoop(bool $runOnce = false): void
    {
        $pid = getmypid();
        File::put($this->pidFile, (string) $pid);
        @chmod($this->pidFile, 0666);

        Log::info("FlixGrab Daemon iniciado (PID: {$pid})");

        $lastAccountCheck = 0;

        try {
            while (true) {
                $now = time();

                // 1. Escaneo de cuentas OTT conectadas cada 30 segundos
                if (($now - $lastAccountCheck) >= 30) {
                    $this->syncConnectedAccountsAutoDownload();
                    $lastAccountCheck = $now;
                }

                // 2. Procesar tareas de descarga pendientes
                $this->processPendingTasks();

                if ($runOnce) {
                    break;
                }

                // Verificar si se eliminó el archivo PID para salir limpiamente
                if (!File::exists($this->pidFile)) {
                    Log::info("PID file removido. Deteniendo loop del FlixGrab Daemon.");
                    break;
                }

                sleep(4);
            }
        } finally {
            @unlink($this->pidFile);
            Log::info("FlixGrab Daemon finalizado.");
        }
    }

    /**
     * Ejecuta un ciclo de procesamiento de tareas y cuentas.
     */
    public function processIteration(): void
    {
        $this->syncConnectedAccountsAutoDownload();
        $this->processPendingTasks();
    }

    /**
     * Sincroniza cuentas conectadas con auto_download activo.
     */
    protected function syncConnectedAccountsAutoDownload(): void
    {
        try {
            $accounts = OttProviderAccount::where('status', 'connected')
                ->where('auto_download', true)
                ->get();

            foreach ($accounts as $account) {
                $samples = $this->ottSyncService->getAllCatalogSamples()[$account->provider] ?? [];
                foreach ($samples as $sample) {
                    $extId = $sample['external_id'] ?? null;
                    $titleName = $sample['title'] ?? null;
                    if (!$titleName) continue;

                    // Verificar si ya existe en títulos o en tareas
                    $existsInTitles = Title::where('name', $titleName)
                        ->orWhere('slug', 'LIKE', "%{$account->provider}%")
                        ->exists();

                    $existsInTasks = OttDownloadTask::where('provider', $account->provider)
                        ->where(function ($q) use ($extId, $titleName) {
                            if ($extId) $q->where('external_id', $extId);
                            $q->orWhere('title', $titleName);
                        })
                        ->exists();

                    if (!$existsInTitles && !$existsInTasks) {
                        $quality = $account->quality_profile ?: '1080p';
                        $mediaType = $sample['type'] ?? 'movie';
                        $folderSubdir = $mediaType === 'series' ? 'series' : 'movies';
                        $fileName = Str::slug($titleName) . '.' . Str::lower(str_replace(' ', '', $quality)) . '.mp4';
                        $baseDir = $account->session_metadata['storage_path'] ?? storage_path("media/{$account->provider}/{$folderSubdir}");
                        $targetDir = OttSyncService::resolveAndEnsureTargetFolder($baseDir, $account->provider);
                        $localFilePath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

                        OttDownloadTask::create([
                            'provider' => $account->provider,
                            'ott_account_id' => $account->id,
                            'title' => $titleName,
                            'media_type' => $mediaType,
                            'external_id' => $extId,
                            'quality' => $quality,
                            'status' => 'pending',
                            'progress' => 0,
                            'download_speed' => '0.0 MB/s',
                            'eta' => 'En cola...',
                            'total_bytes' => $sample['file_size'] ?? (2000 * 1024 * 1024),
                            'downloaded_bytes' => 0,
                            'file_path' => $localFilePath,
                            'metadata' => [
                                'poster_url' => $sample['poster_url'] ?? null,
                                'backdrop_url' => $sample['backdrop_url'] ?? null,
                                'overview' => $sample['overview'] ?? null,
                                'release_year' => $sample['release_year'] ?? null,
                                'genre' => $sample['genre'] ?? null,
                            ],
                            'started_at' => null,
                        ]);

                        MediaIngestTask::create([
                            'source_url' => "https://{$account->provider}.com/title/{$extId}",
                            'provider' => $account->provider,
                            'title' => $titleName,
                            'target_folder' => $targetDir,
                            'status' => 'pending',
                            'progress' => 0,
                            'download_speed' => '0.0 MB/s',
                            'eta' => 'En cola...',
                            'quality' => $quality,
                            'auto_index' => true,
                            'output_file' => $localFilePath,
                        ]);

                        Log::info("FlixGrab Daemon: Auto-descarga programada para '{$titleName}' ({$account->provider})");
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error en syncConnectedAccountsAutoDownload: " . $e->getMessage());
        }
    }

    /**
     * Procesa las tareas de descarga activas y en cola.
     */
    protected function processPendingTasks(): void
    {
        try {
            $tasks = OttDownloadTask::whereIn('status', ['pending', 'downloading'])
                ->where('progress', '<', 100)
                ->orderByRaw("FIELD(status, 'downloading', 'pending'), id ASC")
                ->limit(5)
                ->get();

            foreach ($tasks as $ottTask) {
                // Sincronizar o crear registro en media_ingest_tasks
                $ingestTask = MediaIngestTask::where('title', $ottTask->title)
                    ->orWhere('output_file', $ottTask->file_path)
                    ->first();

                if (!$ingestTask) {
                    $folder = $ottTask->file_path ? dirname($ottTask->file_path) : storage_path('media/movies');
                    $ingestTask = MediaIngestTask::create([
                        'source_url' => "https://{$ottTask->provider}.com/title/{$ottTask->external_id}",
                        'provider' => $ottTask->provider,
                        'title' => $ottTask->title,
                        'target_folder' => $folder,
                        'status' => 'downloading',
                        'progress' => max(5, (int) $ottTask->progress),
                        'download_speed' => $ottTask->download_speed ?: '25.0 MB/s',
                        'eta' => $ottTask->eta ?: '02:30 min',
                        'quality' => $ottTask->quality ?: '1080p',
                        'auto_index' => true,
                        'output_file' => $ottTask->file_path,
                        'started_at' => now(),
                    ]);
                }

                // Ejecutar el proceso de descarga física e indexación
                $job = new \App\Jobs\ProcessMediaIngestJob($ingestTask->id);
                $job->handle($this->ingestService);

                // Actualizar task a completed
                $ottTask->refresh();
                if ($ottTask->status !== 'completed' && File::exists($ottTask->file_path) && File::size($ottTask->file_path) > 1048576) {
                    $ottTask->update([
                        'status' => 'completed',
                        'progress' => 100,
                        'download_speed' => '0.0 MB/s',
                        'eta' => 'Completado',
                        'completed_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error en processPendingTasks: " . $e->getMessage());
        }
    }

    /**
     * Verifica si un PID de proceso está activo en el sistema.
     */
    protected function isPidRunning(int $pid): bool
    {
        if ($pid <= 0) return false;

        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec("tasklist /FI \"PID eq {$pid}\" 2>&1");
            return !empty($output) && (str_contains(strtolower($output), 'php') || str_contains(strtolower($output), 'python'));
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Linux / Docker
        return file_exists("/proc/{$pid}");
    }

    /**
     * Resuelve el binario ejecutable de PHP.
     */
    protected function resolvePhpBinary(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'php';
        }

        if (file_exists('/usr/local/bin/php')) {
            return '/usr/local/bin/php';
        }
        if (file_exists('/usr/bin/php')) {
            return '/usr/bin/php';
        }
        if (defined('PHP_BINARY') && !str_contains(PHP_BINARY, 'php-fpm')) {
            return PHP_BINARY;
        }

        return 'php';
    }
}
