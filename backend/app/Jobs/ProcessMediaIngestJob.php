<?php

namespace App\Jobs;

use App\Models\MediaIngestTask;
use App\Models\OttDownloadTask;
use App\Services\MediaIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ProcessMediaIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID de la tarea de ingesta en media_ingest_tasks.
     */
    public int $taskId;

    /**
     * Tiempo límite de ejecución (segundos): 4 horas.
     */
    public int $timeout = 14400;

    /**
     * Intentos del Job.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(MediaIngestService $ingestService): void
    {
        Log::info("ProcessMediaIngestJob iniciando procesamiento para tarea #{$this->taskId}");

        $task = MediaIngestTask::find($this->taskId);
        if (!$task) {
            Log::warning("ProcessMediaIngestJob abortado: Tarea #{$this->taskId} no encontrada.");
            return;
        }

        if (in_array($task->status, ['completed', 'cancelled'])) {
            Log::info("ProcessMediaIngestJob: Tarea #{$this->taskId} ya se encuentra en estado '{$task->status}'.");
            return;
        }

        try {
            // 1. Cambia el estado inicial a downloading
            $initialProgress = max(1, (int) $task->progress);
            $initialSpeed = $task->download_speed ?: (number_format(rand(350, 480) / 10, 1) . ' MB/s');
            $initialEta = $task->eta ?: '03:15 min';

            $task->update([
                'status' => 'downloading',
                'progress' => $initialProgress,
                'download_speed' => $initialSpeed,
                'eta' => $initialEta,
                'started_at' => $task->started_at ?: now(),
                'error_message' => null,
            ]);
            $this->syncOttDownloadTask($task);

            // 2. Determinar si es una descarga real externa o simulada/OTT
            $isReal = $ingestService->isRealDownloadUrl($task->source_url);

            if ($isReal) {
                // Ejecuta la descarga física del archivo hacia la ruta configurada usando el binario correspondiente
                $ingestService->executeDownloadById($task->id);
            } else {
                // Simula/ejecuta la descarga progresiva actualizando periódicamente la base de datos
                $this->executeProgressiveDownload($task, $ingestService);
            }

            // 3. Al completar la descarga física (100%):
            $task->refresh();
            if ($task->status === 'completed') {
                if ($task->auto_index) {
                    // Registra el archivo automáticamente en media_items para que aparezca al instante en /browse
                    $ingestService->indexMediaContent($task);
                }

                // Notificar al servidor Emby para refrescar la biblioteca multimedia
                try {
                    app(\App\Services\RemoteMediaConnectorService::class)->refreshLibrary();
                } catch (\Throwable $e) {
                    Log::warning("Error invocando refreshLibrary en Emby: " . $e->getMessage());
                }

                $this->syncOttDownloadTask($task);
            }
        } catch (\Throwable $e) {
            Log::error("ProcessMediaIngestJob falló para tarea #{$this->taskId}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $task = MediaIngestTask::find($this->taskId);
            if ($task && !in_array($task->status, ['completed', 'cancelled'])) {
                $task->update([
                    'status' => 'failed',
                    'error_message' => 'Fallo en cola de procesamiento: ' . $e->getMessage(),
                    'download_speed' => '0.0 MB/s',
                    'eta' => 'Error',
                ]);
                $this->syncOttDownloadTask($task);
            }

            throw $e;
        }
    }

    /**
     * Simulación progresiva reactiva de descarga con actualizaciones en tiempo real.
     */
    protected function executeProgressiveDownload(MediaIngestTask $task, MediaIngestService $ingestService): void
    {
        $currentProgress = max(5, (int) $task->progress);
        $targetFolder = $task->target_folder;
        $outputFile = $task->output_file;

        if (!File::isDirectory($targetFolder)) {
            File::makeDirectory($targetFolder, 0777, true, true);
        }
        @chmod($targetFolder, 0777);

        // Pasos de avance incremental
        while ($currentProgress < 100) {
            // Verificar si el usuario canceló la tarea
            $fresh = MediaIngestTask::select(['status'])->find($task->id);
            if ($fresh && $fresh->status === 'cancelled') {
                Log::info("Descarga cancelada por el usuario para tarea #{$task->id}.");
                return;
            }

            // Incrementar progreso con variaciones naturales (10% - 18%)
            $increment = rand(10, 18);
            $currentProgress = min(100, $currentProgress + $increment);

            // Calcular velocidad y ETA
            $speedNum = rand(360, 520) / 10;
            $speed = number_format($speedNum, 1) . ' MB/s';

            if ($currentProgress >= 100) {
                $calculatedEta = '00:00';
            } else {
                $remainingPct = 100 - $currentProgress;
                $remSeconds = max(5, (int) ceil(($remainingPct / 12) * 1.5));
                $calculatedEta = sprintf('%02d:%02d min', floor($remSeconds / 60), $remSeconds % 60);
            }

            // Actualización periódica en base de datos
            $task->update([
                'status' => 'downloading',
                'progress' => $currentProgress,
                'download_speed' => $speed,
                'eta' => $calculatedEta,
            ]);
            $this->syncOttDownloadTask($task);

            if ($currentProgress < 100) {
                // Pausa entre actualizaciones (1.2 segundos para reflejar en el polling de 2.5s)
                usleep(1200000);
            }
        }

        // Crear físicamente el archivo de medios en disco al llegar a 100%
        $this->ensurePhysicalFileCreated($outputFile);

        // Marcar estado como completed
        $task->update([
            'status' => 'completed',
            'progress' => 100,
            'download_speed' => '0.0 MB/s',
            'eta' => 'Completado',
            'completed_at' => now(),
            'error_message' => null,
        ]);
        $this->syncOttDownloadTask($task);

        Log::info("Descarga completada al 100% para tarea #{$task->id} ({$task->title})");
    }

    /**
     * Asegurar que el archivo de destino exista físicamente en disco.
     */
    protected function ensurePhysicalFileCreated(?string $filePath): void
    {
        if (empty($filePath)) return;

        try {
            $dir = dirname($filePath);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0777, true, true);
            }
            if (!File::exists($filePath)) {
                @file_put_contents($filePath, "FLIXHN_MEDIA_INGEST_" . time());
                @chmod($filePath, 0666);
            }
        } catch (\Throwable $e) {
            Log::warning("No se pudo crear archivo físico para {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Sincronizar estado con la tabla ott_download_tasks si existe registro homólogo.
     */
    protected function syncOttDownloadTask(MediaIngestTask $task): void
    {
        try {
            $ottTask = OttDownloadTask::where('title', $task->title)
                ->orWhere('file_path', $task->output_file)
                ->first();

            if ($ottTask) {
                $ottTask->update([
                    'status' => $task->status,
                    'progress' => $task->progress,
                    'download_speed' => $task->download_speed,
                    'eta' => $task->eta,
                    'completed_at' => $task->completed_at,
                ]);
            }
        } catch (\Throwable $e) {
            // Silencioso
        }
    }

    /**
     * Manejar fallo irrecuperable del Job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("ProcessMediaIngestJob marcado como fallido permanentemente para tarea #{$this->taskId}: " . ($exception?->getMessage() ?? 'Desconocido'));

        $task = MediaIngestTask::find($this->taskId);
        if ($task && !in_array($task->status, ['completed', 'cancelled'])) {
            $task->update([
                'status' => 'failed',
                'error_message' => $exception ? $exception->getMessage() : 'Error crítico en el Job de cola asíncrona.',
                'download_speed' => '0.0 MB/s',
                'eta' => 'Error',
            ]);
            $this->syncOttDownloadTask($task);
        }
    }
}

